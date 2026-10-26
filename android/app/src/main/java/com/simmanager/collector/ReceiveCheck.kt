package com.simmanager.collector

import android.Manifest
import android.app.Activity
import android.app.AlertDialog
import android.content.*
import android.content.pm.PackageManager
import android.graphics.Color
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.Handler
import android.os.Looper
import android.telephony.SubscriptionManager
import android.widget.*
import org.json.JSONObject
import java.security.SecureRandom
import java.util.concurrent.Executors

/** Local evidence is scoped to the actual physical subscription, marker, expiry and generation. */
class ReceiveCheck(private val activity:Activity) {
 companion object {
  fun record(context:Context,sub:Int,body:String){
   val p=context.getSharedPreferences("config",0);if(p.getLong("check_local_at",0)>0||!p.getString("check_terminal","").isNullOrEmpty())return;val code=p.getString("check_code","")?:""
   if(localCheckMatches(sub,p.getInt("check_sub",-2),p.getString("config_generation","legacy")?:"legacy",p.getString("check_generation","")?:"",System.currentTimeMillis(),p.getLong("check_expiry",0),body,code))p.edit().putLong("check_local_at",System.currentTimeMillis()).apply()
  }
 }
 private val p=activity.getSharedPreferences("config",0)
 private val handler=Handler(Looper.getMainLooper())
 private val worker=Executors.newSingleThreadExecutor()
 private var dialog:AlertDialog?=null
 @Volatile private var closed=false
 private var terminal=false
 private var busy=false
 private var code=""
 private var generation=""
 private var selectedSub=-1
 private var targetSlot=0
 private var targetPhone=""
 private var targetShop=""
 private var uploaded=false
 private var assigned=false
 private var lastPoll=0L
 private var expiry=0L
 private var transientError=""
 private var client:DeviceClient?=null
 private lateinit var steps:LinearLayout
 private val stepRows=mutableListOf<TextView>()
 private lateinit var countdown:TextView
 private lateinit var resultLabel:TextView
 private lateinit var destination:TextView
 private lateinit var codeLabel:TextView
 private lateinit var copy:Button
 private val tick=object:Runnable{override fun run(){
  if(closed||terminal)return
  if(CollectorService.instance==null){finish("failure","采集服务已停止，无法继续验证收码。请启动采集后重新检查。");return}
  if(!matchesConfig()){finish("failure","配置已变更，旧配置的测试已停止。请重新开始。");return}
  render()
  if(expiry>0&&System.currentTimeMillis()>=expiry){finish("expired",timeoutMessage());return}
  if(receiveCheckShouldPoll(closed,terminal,busy)&&System.currentTimeMillis()-lastPoll>=3000)status()
  if(!closed&&!terminal)handler.postDelayed(this,1000)
 }}
 fun close(){if(closed)return;closed=true;dialog?.dismiss();handler.removeCallbacksAndMessages(null);worker.shutdownNow()}
 private fun matchesConfig()=generation==p.getString("config_generation","legacy")&&selectedSub==p.getInt("check_sub",-1)&&code==p.getString("check_code","")
 private fun notice(message:String){AlertDialog.Builder(activity).setTitle("短信收发链路检查").setMessage(message).setPositiveButton("知道了",null).show();worker.shutdownNow()}
 private fun text(value:String,size:Int=15)=TextView(activity).apply{text=value;textSize=size.toFloat();setPadding(0,10,0,10);setTextColor(Color.rgb(25,51,72))}
 private fun showPanel(){
  val body=LinearLayout(activity).apply{orientation=LinearLayout.VERTICAL;val pad=(20*resources.displayMetrics.density).toInt();setPadding(pad,pad,pad,pad)}
  body.addView(text("需要另一部手机发送测试短信，不会自动发",17).apply{setTypeface(typeface,android.graphics.Typeface.BOLD)})
  body.addView(text("本检查验证接收 → 上传 → 号码 / 店铺归属，不会自动查询余额。",13))
  destination=text("正在锁定本次检查的号码与卡槽…",14);body.addView(destination)
  codeLabel=text("正在连接后台…",20).apply{typeface=android.graphics.Typeface.MONOSPACE;setTextIsSelectable(true)};body.addView(codeLabel)
  copy=Button(activity).apply{text="复制测试短信正文";isEnabled=false;setOnClickListener{(activity.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager).setPrimaryClip(ClipData.newPlainText("测试短信","SIMTEST-$code"));Toast.makeText(activity,"已复制，请用另一部手机发送到上述号码",Toast.LENGTH_LONG).show()}};body.addView(copy)
  countdown=text("正在验证后台连接…",14);body.addView(countdown)
  steps=LinearLayout(activity).apply{orientation=LinearLayout.VERTICAL};body.addView(steps)
  for(value in listOf("○ ① 后台连接　正在验证","○ ② 本机接收　等待开始","○ ③ 上传确认　等待接收","○ ④ 号码 / 店铺　等待上传")){val view=text(value,14).apply{setPadding(16,16,16,16)};stepRows.add(view);steps.addView(view,LinearLayout.LayoutParams(-1,-2).apply{bottomMargin=8})};paintSteps()
  resultLabel=text("",15);body.addView(resultLabel)
  dialog=AlertDialog.Builder(activity).setTitle("短信收码检查 · 卡槽 $targetSlot").setView(ScrollView(activity).apply{addView(body)}).setPositiveButton("关闭",null).create().also{it.setOnDismissListener{close()};it.show()}
 }
 fun start(){
  if(CollectorService.instance==null){notice("请先保存配置并启动采集，再开始检查。");return}
  if(listOf(Manifest.permission.RECEIVE_SMS,Manifest.permission.READ_PHONE_STATE).any{activity.checkSelfPermission(it)!=PackageManager.PERMISSION_GRANTED}){notice("请先开启短信和电话 / SIM 必需权限。");return}
  val cm=activity.getSystemService(ConnectivityManager::class.java)
  if(cm.getNetworkCapabilities(cm.activeNetwork)?.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)!=true){notice("手机尚未连接互联网，请联网后重试。");return}
  val sims=try{activity.getSystemService(SubscriptionManager::class.java).activeSubscriptionInfoList.orEmpty()}catch(_:SecurityException){emptyList()}
  if(sims.isEmpty()){notice("没有检测到可用 SIM 卡，请检查实体卡和电话权限。");return}
  generation=p.getString("config_generation","legacy")?:"legacy"
  // Resume only an unfinished, current, still-present physical SIM test. Targets never refresh mid-test.
  val existing=sims.firstOrNull{it.subscriptionId==p.getInt("check_sub",-1)}
  if(existing!=null&&p.contains("check_phone")&&p.contains("check_shop")&&p.contains("check_slot")&&p.getString("check_generation","")==generation&&p.getLong("check_expiry",0)>System.currentTimeMillis()&&p.getString("check_terminal","").isNullOrEmpty()&&p.getString("check_code","")?.matches(Regex("[A-F0-9]{6}"))==true){
   selectedSub=existing.subscriptionId;targetSlot=p.getInt("check_slot",existing.simSlotIndex+1);code=p.getString("check_code","")!!;targetPhone=p.getString("check_phone","")?:"";targetShop=p.getString("check_shop","")?:"";expiry=p.getLong("check_expiry",0)
   client=DeviceClient(activity){!closed&&!activity.isDestroyed};showPanel();copy.isEnabled=true;render();handler.post(tick);return
  }
  val chooser=AlertDialog.Builder(activity).setTitle("选择实际接收短信的卡槽").setItems(sims.map{"卡槽 ${it.simSlotIndex+1} · ${it.carrierName}"}.toTypedArray()){_,index->
   selectedSub=sims[index].subscriptionId;targetSlot=sims[index].simSlotIndex+1
   code=ByteArray(3).also{SecureRandom().nextBytes(it)}.joinToString(""){"%02X".format(it.toInt() and 255)}
   client=DeviceClient(activity){!closed&&!activity.isDestroyed};showPanel();busy=true
   worker.execute{
    val response=runCatching{client!!.post("check",JSONObject().put("subscription_id",selectedSub).put("code",code))}
    handler.post{busy=false;if(closed)return@post
     response.fold(onSuccess={r->
      if(generation!=p.getString("config_generation","legacy")){finish("failure","配置已变更，请重新开始检查。");return@fold}
      targetPhone=r.optString("phone").takeUnless{it=="null"}?:"";targetShop=r.optString("shop").takeUnless{it=="null"}?:""
      expiry=localCheckDeadline(System.currentTimeMillis(),r.optLong("started_at",0),r.optLong("expires_at",0))
      p.edit().putString("check_code",code).putInt("check_sub",selectedSub).putInt("check_slot",targetSlot).putString("check_phone",targetPhone).putString("check_shop",targetShop).putString("check_generation",generation).putLong("check_expiry",expiry).remove("check_local_at").remove("check_terminal").remove("check_result").apply()
      copy.isEnabled=true;render();handler.post(tick)
     },onFailure={finish("failure","后台连接失败：${it.message?:it.javaClass.simpleName}。请确认配置与网络后重新检查。")})
    }
   }
  }.setNegativeButton("取消"){_,_->close()}.create()
  dialog=chooser;chooser.setOnCancelListener{close()};chooser.show()
 }
 private fun render(){
  val local=p.getLong("check_local_at",0)>0&&matchesConfig()
  destination.text="本次固定目标：卡槽 $targetSlot\n号码：${targetPhone.ifBlank{"未绑定，请核对实体卡实际号码"}}\n测试开始时店铺：${targetShop.ifBlank{"未绑定"}}"
  codeLabel.text="SIMTEST-$code"
  val remaining=((expiry-System.currentTimeMillis()).coerceAtLeast(0)+999)/1000
  countdown.text=if(terminal)"本次检查已结束"else "剩余 %02d:%02d · 请用另一部手机发送一次".format(remaining/60,remaining%60)
  val stateText="✓ ① 后台连接　已验证\n${if(local)"✓"else "○"} ② 本机接收　${if(local)"已收到本次测试短信"else "等待所选卡槽收到短信"}\n${if(uploaded)"✓"else "○"} ③ 上传确认　${if(uploaded)"后台已收到"else if(local)"本机已收到，等待上传"else "等待本机接收"}\n${if(uploaded&&assigned)"✓"else if(uploaded)"!"else "○"} ④ 号码 / 店铺　${checkAssignmentLabel(uploaded,assigned)}"
  stateText.split("\n").forEachIndexed{index,value->stepRows[index].text=value};paintSteps()
  resultLabel.text=transientError
 }
 private fun timeoutMessage()=when{p.getLong("check_local_at",0)>0&&!uploaded->"检查已到期：本机已收到测试短信，但后台尚未确认上传。请保持联网并检查最近同步结果后重新检查。";uploaded&&assigned->"检查已到期：后台有记录，但本机未取得所选实体卡收到本次短信的证据，不能判定成功。请核对物理卡槽后重试。";else->"检查已到期：尚未在所选卡槽确认收到本次短信。请用另一部手机向固定目标发送复制的测试正文，再重新检查。"}
 private fun paintSteps(){for(view in stepRows){val value=view.text.toString();val ok=value.startsWith("✓");val failed=value.startsWith("!");view.background=android.graphics.drawable.GradientDrawable().apply{cornerRadius=12f;setColor(if(ok)Color.rgb(228,246,238)else if(failed)Color.rgb(255,234,234)else Color.rgb(240,244,248))};view.setTextColor(if(failed)Color.rgb(175,45,45)else if(ok)Color.rgb(22,123,120)else Color.rgb(84,103,119))}}
 private fun finish(state:String,message:String){
  if(terminal||closed)return
  terminal=true;handler.removeCallbacks(tick);copy.isEnabled=false
  val success=state=="success"
  if(expiry==0L){listOf("! ① 后台连接　未通过","○ ② 本机接收　尚未开始","○ ③ 上传确认　尚未开始","○ ④ 号码 / 店铺　尚未开始").forEachIndexed{index,value->stepRows[index].text=value};paintSteps()}
  resultLabel.text=(if(success)"✓ 检查成功\n"else "! 检查未通过\n")+message
  resultLabel.setTextColor(if(success)Color.rgb(22,123,120)else Color.rgb(175,45,45));countdown.text="本次检查已结束 · 关闭后可重新检查"
  if(p.getString("check_generation","")==generation&&p.getString("check_code","")==code)p.edit().putString("check_terminal",state).putString("check_result",message).putLong("check_finished_at",System.currentTimeMillis()).apply()
  OperationFeedback.notify(activity,if(success)"✓ 短信收码检查成功"else "! 短信收码检查未通过",if(success)"已验证本机接收、后台上传和测试开始时号码 / 店铺归属。"else "请打开 Umax SIM 工作台查看检查结果与操作步骤。",4902)
  worker.shutdown()
 }
 private fun status(){
  if(!receiveCheckShouldPoll(closed,terminal,busy))return
  busy=true;lastPoll=System.currentTimeMillis()
  worker.execute{
   val response=runCatching{client!!.post("check-status",JSONObject().put("code",code))}
   handler.post{busy=false;if(closed||terminal)return@post
    if(!matchesConfig()){finish("failure","配置已变更，旧配置的测试已停止。");return@post}
    val r=response.getOrNull();if(r!=null){uploaded=r.optBoolean("received");assigned=r.optBoolean("assigned")}
    transientError=if(response.isFailure)"! 暂时无法查询后台：${response.exceptionOrNull()?.message?:"网络异常"}。会继续重试，倒计时到期后给出结果。"else ""
    val local=p.getLong("check_local_at",0)>0
    render()
    when(receiveCheckOutcome(local,uploaded,assigned,r?.optBoolean("expired")==true||System.currentTimeMillis()>=expiry)){
     "success"->finish("success","已确认所选实体卡收到本次短信、后台收到上传，并匹配测试开始时的号码 / 店铺。")
     "failure"->finish("failure","短信已到后台，但与测试开始时的号码 / 店铺不匹配。请在后台检查所选卡槽绑定后重新测试。")
     "expired"->finish("expired",timeoutMessage())
    }
   }
  }
 }
}
