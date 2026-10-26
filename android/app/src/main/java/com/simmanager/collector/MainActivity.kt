package com.simmanager.collector

import android.Manifest
import android.app.Activity
import android.content.ClipboardManager
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import android.text.InputType
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.widget.*
import org.json.JSONArray
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class MainActivity:Activity(){
 private val ink=Color.rgb(25,51,72)
 private val muted=Color.rgb(126,146,160)
 private val teal=Color.rgb(22,123,120)
 private val pageBackground=Color.rgb(244,247,250)
 private val handler=Handler(Looper.getMainLooper())
 private lateinit var heroTitle:TextView
 private lateinit var heroSubtitle:TextView
 private lateinit var hero:LinearLayout
 private lateinit var primaryAction:Button
 private lateinit var permissionSettingsAction:Button
 private lateinit var cancelStartAction:Button
 private var legacyNumberConsent:CheckBox?=null
 private var numberConsentRevision=0L
 private lateinit var stopAction:Button
 private lateinit var serverName:TextView
 private lateinit var serverState:TextView
 private lateinit var lastSync:TextView
 private lateinit var remoteStatus:TextView
 private val remoteSeen=mutableSetOf<String>()
 private lateinit var phoneStatus:TextView
 private lateinit var queueCount:TextView
 private lateinit var permissionState:TextView
 private lateinit var setupList:LinearLayout
 private lateinit var setupCard:LinearLayout
 private lateinit var checklistAction:Button
 private lateinit var screenScroll:ScrollView
 private var selectedTab=0
 private val tabScrolls=mutableListOf<ScrollView>()
 private val tabButtons=mutableListOf<Button>()
 private val scrollPositions=IntArray(3)
 private val expandedDisclosures=mutableSetOf<String>()
 private var recommendedExpanded=false
 private var optionalExpanded=false
 private lateinit var manualConfig:LinearLayout
 private var setupSnapshot=""
 private fun selectTab(index:Int){
  if(tabScrolls.isEmpty())return
  if(tabScrolls[selectedTab].visibility==View.VISIBLE)scrollPositions[selectedTab]=screenScroll.scrollY
  selectedTab=index.coerceIn(0,2);screenScroll=tabScrolls[selectedTab]
  tabScrolls.forEachIndexed{i,v->v.visibility=if(i==selectedTab)View.VISIBLE else View.GONE}
  tabButtons.forEachIndexed{i,v->v.isSelected=i==selectedTab;v.setTextColor(if(i==selectedTab)teal else muted);v.background=shape(if(i==selectedTab)Color.rgb(225,242,238)else Color.WHITE)}
  screenScroll.post{screenScroll.scrollTo(0,scrollPositions[selectedTab])}
 }
 private fun navigateTo(index:Int,target:View?=null){
  selectTab(index)
  if(target!=null)screenScroll.post{screenScroll.smoothScrollTo(0,target.top)}
 }
 private fun disclosure(parent:LinearLayout,label:String,body:LinearLayout){
  body.visibility=if(label in expandedDisclosures)View.VISIBLE else View.GONE
  val button=action(label){body.visibility=if(body.visibility==View.VISIBLE)View.GONE else View.VISIBLE;if(body.visibility==View.VISIBLE)expandedDisclosures.add(label)else expandedDisclosures.remove(label)}
  parent.addView(button,LinearLayout.LayoutParams(-1,-2));space(parent,8);parent.addView(body)
 }
 private var receiveCheck:ReceiveCheck?=null
 private var updater:AppUpdater?=null
 private lateinit var smsOverview:TextView
 private var connectionErrorVisible=false
 private var actionAt=0L
 private var actionResultAt=0L
 private lateinit var feedback:TextView
 private lateinit var details:TextView
 private lateinit var simList:LinearLayout
 private lateinit var configCard:LinearLayout
 private lateinit var addressInput:EditText
 private var importedPairing:PairingConfig?=null
 private var pairingPreview:android.app.AlertDialog?=null
 private val scanRequest=551
 private val cameraRequest=552
 private lateinit var tokenInput:EditText
 private var pendingStart=false
 private var startPermissionInFlight=false
 private var startPermissionDenied=false
 private var pendingStartConfig:String?=null
 private var startGuide:android.app.AlertDialog?=null
 private var previousSimSnapshot=""
 private val telemetryWorker=java.util.concurrent.Executors.newSingleThreadExecutor()
 private var latestTelemetry=JSONObject()
 private var latestLocalSubs:JSONArray?=null
 private var lastTelemetryAt=0L
 private var samplingTelemetry=false
 private var activeUi=false
 private val refresh=object:Runnable{override fun run(){updateStatus();handler.postDelayed(this,2000)}}
 private fun dp(n:Int)=(n*resources.displayMetrics.density).toInt()
 private fun prefs()=getSharedPreferences("config",MODE_PRIVATE)
 private fun allowed(p:String)=checkSelfPermission(p)==PackageManager.PERMISSION_GRANTED
 private fun numberPermission()=if(Build.VERSION.SDK_INT>=26)Manifest.permission.READ_PHONE_NUMBERS else Manifest.permission.READ_PHONE_STATE
 private fun numberHintsAllowed()=canReadSimPhoneHint(Build.VERSION.SDK_INT,allowed(Manifest.permission.READ_PHONE_STATE),if(Build.VERSION.SDK_INT>=26)allowed(Manifest.permission.READ_PHONE_NUMBERS) else false,prefs().getBoolean("legacy_phone_hint_consent",false))
 private fun required()=listOf(Manifest.permission.RECEIVE_SMS,Manifest.permission.READ_PHONE_STATE)
 private fun shape(color:Int,border:Int?=null)=GradientDrawable().apply{setColor(color);cornerRadius=dp(12).toFloat();if(border!=null)setStroke(dp(1),border)}
 private fun text(value:String,size:Int=14,color:Int=ink,bold:Boolean=false)=TextView(this).apply{text=value;textSize=size.toFloat();setTextColor(color);if(bold)setTypeface(typeface,Typeface.BOLD);setLineSpacing(dp(3).toFloat(),1f)}
 private fun column()=LinearLayout(this).apply{orientation=LinearLayout.VERTICAL}
 private fun row()=LinearLayout(this).apply{orientation=LinearLayout.HORIZONTAL;gravity=Gravity.CENTER_VERTICAL}
 private fun space(parent:LinearLayout,height:Int){parent.addView(View(this),LinearLayout.LayoutParams(1,dp(height)))}
 private fun card(parent:LinearLayout):LinearLayout {val view=column().apply{background=shape(Color.WHITE,Color.rgb(226,233,239));setPadding(dp(20),dp(20),dp(20),dp(20))};parent.addView(view,LinearLayout.LayoutParams(-1,-2).apply{bottomMargin=dp(14)});return view}
 private fun action(label:String,primary:Boolean=false,callback:()->Unit)=Button(this).apply{text=label;textSize=14f;isAllCaps=false;stateListAnimator=null;elevation=0f;setTextColor(if(primary)Color.WHITE else teal);background=shape(if(primary)teal else Color.rgb(235,246,243));minHeight=dp(46);setPadding(dp(14),dp(10),dp(14),dp(10));setOnClickListener{callback()}}
 private fun field(parent:LinearLayout,label:String,hint:String,secret:Boolean=false):EditText {parent.addView(text(label,12,muted));space(parent,7);val input=EditText(this).apply{this.hint=hint;textSize=14f;isSingleLine=true;inputType=if(secret)InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD else InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_URI;setTextColor(ink);setHintTextColor(Color.rgb(162,178,189));setPadding(dp(12),dp(12),dp(12),dp(12));background=shape(Color.WHITE,Color.rgb(217,228,235));minHeight=dp(48)};parent.addView(input,LinearLayout.LayoutParams(-1,-2));space(parent,15);return input}
 private fun show(message:String,tone:String="info",notify:Boolean=true){
  feedback.text=message;feedback.visibility=View.VISIBLE
  val color=when(tone){"success"->teal;"error"->Color.rgb(175,45,45);else->Color.rgb(138,88,20)}
  feedback.setTextColor(color);feedback.background=shape(when(tone){"success"->Color.rgb(225,245,237);"error"->Color.rgb(255,234,234);else->Color.rgb(255,245,222)})
  feedback.setPadding(dp(14),dp(14),dp(14),dp(14))
  if(notify){Toast.makeText(this,message,Toast.LENGTH_LONG).show();}
 }
 private fun launchSettings(intent:Intent):Boolean=try{startActivity(intent);true}catch(_:Exception){false}
 private fun permission(p:String){if(allowed(p)){show("此权限已开启。如需修改，可在应用设置操作。");return};requestPermissions(arrayOf(p),3)}
 private fun manualGuide(key:String,title:String,steps:String){
  val message=brandGuide(key)+"\n\n"+steps+"\n\n系统未提供可靠的读取接口，App 无法自动检测。完成后返回，点“我已检查并开启”。绿色状态也只代表你的手动确认。"
  android.app.AlertDialog.Builder(this).setTitle(title).setMessage(message)
   .setNeutralButton("重置确认"){_,_->prefs().edit().remove("setup_"+key).apply();setupSnapshot="";updateStatus()}
   .setNegativeButton("去系统设置"){_,_->openManufacturerSettings(key)}
   .setPositiveButton("我已检查并开启"){_,_->prefs().edit().putBoolean("setup_"+key,true).apply();setupSnapshot="";show("$title：已记录手动确认。", "success");updateStatus()}.show()
 }
 private fun brandGuide(key:String):String {
  val maker=Build.MANUFACTURER.lowercase(Locale.ROOT)
  val family=when {
   maker.contains("vivo")||maker.contains("iqoo")->"vivo / iQOO：设置 → 应用与权限 → 权限管理 → 自启动；i 管家 → 电池管理 → 后台高耗电，允许 Umax SIM 工作台。"
   maker.contains("xiaomi")||maker.contains("redmi")->"小米 / 红米 / HyperOS：设置 → 应用设置 → 授权管理 → 自启动；应用信息 → 省电策略 → 无限制。"
   maker.contains("huawei")||maker.contains("honor")->"华为 / 荣耀：设置 → 应用 → 应用启动管理 → Umax SIM 工作台，关闭自动管理，手动允许自启动、关联启动、后台活动。"
   maker.contains("oppo")||maker.contains("realme")||maker.contains("oneplus")->"OPPO / 一加 / realme：设置 → 应用管理 → Umax SIM 工作台 → 耗电管理，允许后台活动；在自启动管理中允许启动。"
   maker.contains("samsung")->"三星：设置 → 电池 → 后台使用限制 → 从不自动休眠的应用，加入 Umax SIM 工作台；应用信息 → 电池 → 不受限制。"
   else->"通用 Android：设置 → 应用 → Umax SIM 工作台 → 电池，选择不限制；若系统提供自启动管理，请允许启动。"
  }
  return "本机：${Build.MANUFACTURER} ${Build.MODEL} / Android ${Build.VERSION.RELEASE}\n"+family+(if(key=="task")"\n最近任务中长按应用图标或卡片，查找锁定 / 保持打开。"else "")+"\n不同系统版本菜单可能不同；无法跳转时按此路径手动查找。"
 }
 private fun setupComplete(){
  val requiredReady=required().all{allowed(it)}
  val nm=getSystemService(NOTIFICATION_SERVICE) as android.app.NotificationManager
  val pm=getSystemService(POWER_SERVICE) as android.os.PowerManager
  val pending=mutableListOf<String>()
  if(!androidx.core.app.NotificationManagerCompat.from(this).areNotificationsEnabled())pending.add("运行通知")
  if(!pm.isIgnoringBatteryOptimizations(packageName))pending.add("系统电池优化")
  for((key,title) in listOf("auto" to "自启动（手动确认）","background" to "后台运行（手动确认）","task" to "最近任务锁定（手动确认）"))if(!prefs().getBoolean("setup_"+key,false))pending.add(title)
  android.app.AlertDialog.Builder(this).setTitle(if(requiredReady&&CollectorService.instance!=null)"基础设置已完成"else "仍有基础设置待完成").setMessage(
   "采集服务：${if(CollectorService.instance!=null)"运行中"else "未运行"}\n必需权限：${if(requiredReady)"已齐全"else "待开启"}\n\n可以按 Home 返回桌面或锁屏，应用会继续尝试接收与上传。请保持联网，并在锁屏后再做一次真实短信检查。\n不要在系统设置中强制停止应用；强制停止后必须手动打开并启动采集。厂商清理可能停止服务，无法保证永久后台运行。\n\n推荐项待处理：${if(pending.isEmpty())"无；手动项仅代表你的确认"else pending.joinToString("、")}\n可选网络状态权限不影响收码。")
   .setNegativeButton("查看清单"){_,_->navigateTo(1,setupCard)}.setPositiveButton("知道了",null).show()
 }
 private fun openManufacturerSettings(key:String){
  if(key=="task"){show("点击系统最近任务键，找到 Umax SIM 工作台，在卡片菜单中选择锁定；返回后再确认。 ");return}
  val maker=Build.MANUFACTURER.lowercase(Locale.ROOT)
  val candidates=when {
   maker.contains("xiaomi")||maker.contains("redmi")->listOf("com.miui.securitycenter" to "com.miui.permcenter.autostart.AutoStartManagementActivity")
   maker.contains("vivo")||maker.contains("iqoo")->listOf("com.vivo.permissionmanager" to "com.vivo.permissionmanager.activity.BgStartUpManagerActivity", "com.iqoo.secure" to "com.iqoo.secure.ui.phoneoptimize.AddWhiteListActivity")
   maker.contains("huawei")||maker.contains("honor")->listOf("com.huawei.systemmanager" to "com.huawei.systemmanager.startupmgr.ui.StartupNormalAppListActivity")
   maker.contains("oppo")||maker.contains("realme")||maker.contains("oneplus")->listOf("com.oplus.safecenter" to "com.oplus.safecenter.startupapp.StartupAppListActivity", "com.coloros.safecenter" to "com.coloros.safecenter.permission.startup.StartupAppListActivity")
   else->emptyList()
  }
  if(key=="auto")for((pkg,cls) in candidates)if(launchSettings(Intent().setComponent(android.content.ComponentName(pkg,cls))))return
  if(key=="background")show("在应用设置中打开“电池 / 后台耗电管理”，选择“允许后台高耗电”或“不限制”。",notify=false)
  if(!launchSettings(Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS,Uri.parse("package:$packageName"))))show("无法打开系统设置，请手动找到“设置 → 应用 → Umax SIM 工作台”。","error")
 }
 private fun updateChecklist(){
  if(!::setupList.isInitialized)return
  val nm=getSystemService(NOTIFICATION_SERVICE) as android.app.NotificationManager
  val pm=getSystemService(POWER_SERVICE) as android.os.PowerManager
  val notification=androidx.core.app.NotificationManagerCompat.from(this).areNotificationsEnabled()&&(Build.VERSION.SDK_INT<33||allowed(Manifest.permission.POST_NOTIFICATIONS))
  val battery=pm.isIgnoringBatteryOptimizations(packageName)
  val fingerprint=listOf(allowed(Manifest.permission.RECEIVE_SMS),allowed(Manifest.permission.READ_PHONE_STATE),notification,battery,allowed(Manifest.permission.ACCESS_COARSE_LOCATION),allowed(Manifest.permission.CAMERA),numberHintsAllowed(),prefs().getBoolean("setup_auto",false),prefs().getBoolean("setup_background",false),prefs().getBoolean("setup_task",false)).joinToString()
  if(fingerprint==setupSnapshot)return;setupSnapshot=fingerprint;setupList.removeAllViews()
  var missing=0
  var group=setupList
  fun groupHeader(label:String,expanded:Boolean,onToggle:()->Unit):LinearLayout{
   val content=column().apply{visibility=if(expanded)View.VISIBLE else View.GONE}
   setupList.addView(action((if(expanded)"▾ "else "▸ ")+label){onToggle();setupSnapshot="";updateChecklist()},LinearLayout.LayoutParams(-1,-2));space(setupList,8);setupList.addView(content)
   return content
  }
  fun item(title:String,state:SetupState,description:String,button:String,callback:()->Unit){
   val pane=column().apply{background=shape(if(state.ready)Color.rgb(235,248,242)else Color.rgb(255,247,232));setPadding(dp(12),dp(12),dp(12),dp(12))}
   pane.addView(text((if(state.ready)"✓ " else "! ")+title,14,ink,true));space(pane,4)
   pane.addView(text(state.status,12,if(state.ready)teal else Color.rgb(153,91,16),true));space(pane,5);pane.addView(text(description,12,muted));space(pane,8)
   pane.addView(action(button,callback=callback),LinearLayout.LayoutParams(-1,-2));group.addView(pane,LinearLayout.LayoutParams(-1,-2).apply{bottomMargin=dp(10)})
  }
  for((title,p) in listOf("短信权限 · 必需" to Manifest.permission.RECEIVE_SMS,"电话 / SIM 权限 · 必需" to Manifest.permission.READ_PHONE_STATE)){
   val granted=allowed(p);if(!granted)missing++
   item(title,setupPermission(granted,true),if(p==Manifest.permission.RECEIVE_SMS)"用于接收新短信。未开启时不能采集。"else "用于识别双卡和对应卡槽。",if(granted)"查看应用设置" else "开启权限"){if(granted)settings()else android.app.AlertDialog.Builder(this).setTitle(title).setItems(arrayOf("请求权限","打开应用设置（没有弹窗时）")){_,which->if(which==0)permission(p)else settings()}.show()}
  }
  group=groupHeader("推荐设置",recommendedExpanded){recommendedExpanded=!recommendedExpanded}
  item("通知 · 推荐",SetupState(if(notification)"已开启"else "建议开启",notification),"显示持续采集通知，方便确认服务是否运行。",if(notification)"查看通知设置"else "开启通知"){
   if(Build.VERSION.SDK_INT>=33&&!allowed(Manifest.permission.POST_NOTIFICATIONS))permission(Manifest.permission.POST_NOTIFICATIONS)
   else if(Build.VERSION.SDK_INT>=26){if(!launchSettings(Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE,packageName)))settings()}else settings()
  }
  item("系统电池优化 · 推荐",SetupState(if(battery)"已取消限制"else "建议取消限制",battery),"系统检测结果；与厂商“后台高耗电”是不同设置。",if(battery)"查看电池优化"else "去取消限制"){
   android.app.AlertDialog.Builder(this).setTitle("取消系统电池优化").setMessage("进入后切换到“所有应用”，找到 Umax SIM 工作台，选择“不优化 / 不限制”。返回后 App 会自动复查。\n\nvivo 的“允许后台高耗电”请在下一项单独设置。").setNegativeButton("取消",null).setPositiveButton("打开设置"){_,_->if(!launchSettings(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS)))settings()}.show()
  }
  item("自启动 · 推荐",setupManual(prefs().getBoolean("setup_auto",false)),"需手动确认；系统升级或重装后请重新检查。","查看引导 / 确认"){manualGuide("auto","允许自启动","在系统“应用启动管理 / 自启动”中找到 Umax SIM 工作台，允许自启动和关联启动。华为 / 荣耀若有自动管理，请切为手动并允许后台活动。")}
  item("后台高耗电 / 不限制 · 推荐",setupManual(prefs().getBoolean("setup_background",false)),"需手动确认；决定锁屏后是否容易被系统停止。","查看引导 / 确认"){manualGuide("background","允许后台运行","按上方本机路径进入，选择“允许后台高耗电 / 不限制 / 允许后台活动”等允许选项。具体名称以你的系统为准。")}
  item("最近任务锁定 · 推荐",setupManual(prefs().getBoolean("setup_task",false)),"需手动确认；可降低一键清理误停的概率。","查看锁定方法 / 确认"){manualGuide("task","锁定最近任务","打开系统最近任务列表，找到 Umax SIM 工作台，长按卡片或打开菜单，选择“锁定 / 保持打开”。没有此功能的机型可跳过，不影响启动。")}
  group=groupHeader("可选权限",optionalExpanded){optionalExpanded=!optionalExpanded}
  val numbers=numberHintsAllowed()
  item("读取本机号码 · 可选",setupPermission(numbers,false),"尝试读取 SIM 提供的中国 / 菲律宾手机号，用于自动关联后台已有号码，无需验证短信；部分 SIM 没有号码，仍可在后台手动绑定。",if(numbers)"查看应用设置"else "尝试读取本机号码"){
   if(numbers)settings()else android.app.AlertDialog.Builder(this).setTitle("尝试读取本机号码").setMessage("授权后尝试读取 SIM 内的手机号，并把号码提示上传到自己的后台。后台在权限及号码唯一匹配时自动关联，无需验证短信。读取不到时，在后台“采集设备 → 绑定手机号”手动绑定；不授权不影响短信采集。").setNegativeButton("暂不授权",null).setPositiveButton("授权读取"){_,_->if(Build.VERSION.SDK_INT<26){prefs().edit().putBoolean("legacy_phone_hint_consent",true).apply();legacyNumberConsent?.isChecked=true};permission(numberPermission());setupSnapshot="";updateChecklist()}.show()
  }
  val network=allowed(Manifest.permission.ACCESS_COARSE_LOCATION)
  item("补充网络状态 · 可选",setupPermission(network,false),"仅补充运营商服务状态，不读取坐标；不影响短信上传。",if(network)"查看应用设置"else "授权可选权限"){if(network)settings()else android.app.AlertDialog.Builder(this).setTitle("补充网络状态").setMessage("系统把部分服务状态归入位置权限。这里只读取运营商状态，不获取坐标；可以跳过。").setNegativeButton("跳过",null).setPositiveButton("继续授权"){_,_->permission(Manifest.permission.ACCESS_COARSE_LOCATION)}.show()}
  if(packageManager.hasSystemFeature(PackageManager.FEATURE_CAMERA_ANY)){
   val camera=allowed(Manifest.permission.CAMERA)
   item("摄像头 · 可选",setupPermission(camera,false),"仅用于扫描后台的手机接入二维码；不影响收码。",if(camera)"查看应用设置"else "开启摄像头"){if(camera)settings()else permission(Manifest.permission.CAMERA)}
  }
  checklistAction.text=if(missing==0)"✓ 必需权限已齐全 · 查看运行检查" else "! $missing 项必需权限待开启 · 去处理"
  permissionState.text=if(missing==0)"必需权限已齐全" else "还有 $missing 项必需权限待开启"
 }

 private fun settings(){startActivity(Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS,Uri.parse("package:$packageName")))}
 private fun hasConfig()=prefs().contains("token")&&ConnectionConfig.url(prefs().getString("url","")?:"")!=null
 private fun timestamp(value:Long)=if(value>0)SimpleDateFormat("MM-dd HH:mm:ss",Locale.CHINA).format(Date(value)) else "尚未同步"
 override fun onCreate(savedInstanceState:Bundle?){
  super.onCreate(savedInstanceState);prefs().let{p->remoteSeen.add("${p.getString("remote_refresh_generation","")}:${remoteFeedbackSignature(p.getLong("remote_refresh_id",0),p.getString("remote_refresh_state","")?:"")}")};pendingStart=savedInstanceState?.getBoolean("pendingStart")?:false
  startPermissionInFlight=savedInstanceState?.getBoolean("startPermissionInFlight")?:false
  startPermissionDenied=savedInstanceState?.getBoolean("startPermissionDenied")?:false
  pendingStartConfig=savedInstanceState?.getString("pendingStartConfig")
  savedInstanceState?.let{state->recommendedExpanded=state.getBoolean("recommendedExpanded");optionalExpanded=state.getBoolean("optionalExpanded");expandedDisclosures.addAll(state.getStringArrayList("expandedDisclosures")?:emptyList())}
  window.statusBarColor=pageBackground;window.navigationBarColor=pageBackground
  val shell=column().apply{setBackgroundColor(pageBackground)}
  shell.setOnApplyWindowInsetsListener{view,insets->view.setPadding(insets.systemWindowInsetLeft,insets.systemWindowInsetTop,insets.systemWindowInsetRight,insets.systemWindowInsetBottom);insets}
  val header=row().apply{setPadding(dp(20),dp(12),dp(20),dp(12))}
  header.addView(ImageView(this).apply{setImageResource(R.drawable.umax_logo);contentDescription="Umax Logo"},LinearLayout.LayoutParams(dp(36),dp(36)).apply{rightMargin=dp(10)})
  header.addView(text("Umax SIM 工作台",18,ink,true),LinearLayout.LayoutParams(0,-2,1f));header.addView(text(BuildConfig.VERSION_NAME,11,muted));shell.addView(header)
  feedback=text("",13,teal).apply{visibility=View.GONE};shell.addView(feedback,LinearLayout.LayoutParams(-1,-2).apply{leftMargin=dp(18);rightMargin=dp(18)})
  val pages=FrameLayout(this);shell.addView(pages,LinearLayout.LayoutParams(-1,0,1f))
  fun page():LinearLayout{
   val scroll=ScrollView(this).apply{isFillViewport=true;visibility=View.GONE}
   val content=column().apply{setPadding(dp(18),dp(12),dp(18),dp(20))};scroll.addView(content);pages.addView(scroll,FrameLayout.LayoutParams(-1,-1));tabScrolls.add(scroll);return content
  }
  val runPage=page();val checkPage=page();val settingsPage=page();screenScroll=tabScrolls[0]
  hero=card(runPage);hero.background=shape(teal)
  heroTitle=text("准备接入手机",23,Color.WHITE,true);heroSubtitle=text("",13,Color.rgb(220,242,235));hero.addView(heroTitle);space(hero,8);hero.addView(heroSubtitle);space(hero,16)
  primaryAction=action("扫码接入") {if(!hasConfig()){navigateTo(2);beginPairingScan()}else if(CollectorService.instance!=null){actionAt=System.currentTimeMillis();CollectorService.requestSync();show("正在连接后台，请稍候…")}else requestStart()}
  primaryAction.background=shape(Color.WHITE);primaryAction.setTextColor(teal);hero.addView(primaryAction,LinearLayout.LayoutParams(-1,-2))
  permissionSettingsAction=action("打开应用设置，允许必需权限"){settings()}.apply{visibility=View.GONE}
  hero.addView(permissionSettingsAction,LinearLayout.LayoutParams(-1,-2))
  cancelStartAction=action("取消此次启动"){clearPendingStart();show("此次启动已取消，采集保持关闭。",notify=false)}.apply{visibility=View.GONE}
  hero.addView(cancelStartAction,LinearLayout.LayoutParams(-1,-2));space(hero,9)
  hero.addView(text("启动时集中申请权限：短信、电话 / SIM 为必需；本机号码用于向自己的后台提供号码提示并尝试关联，通知用于显示运行状态，摄像头用于扫码，位置权限仅补充运营商状态、不获取坐标。可选项可拒绝，不影响收码；系统可能分多次弹窗。",11,Color.WHITE))
  if(Build.VERSION.SDK_INT<26){
   legacyNumberConsent=CheckBox(this).apply{
    text="可选：允许读取 SIM 本机号码并上传号码提示（无需验证短信）";textSize=12f;setTextColor(Color.WHITE)
    isChecked=prefs().getBoolean("legacy_phone_hint_consent",false)
    setOnCheckedChangeListener{_,checked->
     numberConsentRevision++
     prefs().edit().putBoolean("legacy_phone_hint_consent",checked).apply()
     setupSnapshot="";lastTelemetryAt=0;previousSimSnapshot=""
     latestTelemetry.optJSONObject("permissions")?.put("phone_numbers",numberHintsAllowed())
     if(!checked){
      val cached=runCatching{JSONArray(prefs().getString("sim_status","[]"))}.getOrDefault(JSONArray())
      fun removeHints(subs:JSONArray){for(i in 0 until subs.length())subs.optJSONObject(i)?.put("phone_hint",JSONObject.NULL)?.put("phone_hint_source",JSONObject.NULL)}
      removeHints(cached);latestLocalSubs?.let{removeHints(it)}
      prefs().edit().putString("sim_status",cached.toString()).apply()
     }
     if(prefs().getBoolean("enabled",false)&&required().all{allowed(it)}&&CollectorService.instance!=null)CollectorService.requestSync()
     updateStatus()
    }
   };hero.addView(legacyNumberConsent,LinearLayout.LayoutParams(-1,-2))
  }
  val connection=card(runPage);serverName=text("",12,muted);serverState=text("",15,ink,true);lastSync=text("",12,muted);queueCount=text("",12,muted)
  connection.addView(serverState);space(connection,5);connection.addView(serverName);space(connection,12);connection.addView(lastSync);space(connection,5);connection.addView(queueCount)
  checklistAction=action("运行检查"){navigateTo(1,setupCard)};runPage.addView(checklistAction,LinearLayout.LayoutParams(-1,-2));space(runPage,14)
  val simCard=card(runPage);simCard.addView(text("本机 SIM 卡",16,ink,true));space(simCard,10);simList=column();simCard.addView(simList)
  val sms=card(runPage);sms.addView(text("最近短信",16,ink,true));space(sms,8);smsOverview=text("",13,ink);sms.addView(smsOverview)
  val receive=card(checkPage);receive.addView(text("验证收码链路",18,ink,true));space(receive,5);receive.addView(text("用另一部手机发送真实短信，检查接收与上传。",12,muted));space(receive,10)
  receive.addView(action("收码检查（需另一部手机）",true){receiveCheck?.close();receiveCheck=ReceiveCheck(this).also{it.start()}},LinearLayout.LayoutParams(-1,-2))
  val setup=card(checkPage);setupCard=setup;setup.addView(text("运行检查清单",18,ink,true));space(setup,6);permissionState=text("正在检查…",13,muted);setup.addView(permissionState);space(setup,12);setupList=column();setup.addView(setupList)
  val remote=card(checkPage);remote.addView(text("后台请求",16,ink,true));space(remote,8);remoteStatus=text("",13,ink);remote.addView(remoteStatus)
  checkPage.addView(action("后台运行说明"){setupComplete()},LinearLayout.LayoutParams(-1,-2))
  configCard=card(settingsPage);configCard.addView(text("连接配置",16,ink,true));space(configCard,6);configCard.addView(text("在后台“接入手机”打开二维码，扫码后确认服务器即可接入。",12,muted));space(configCard,14)
  configCard.addView(action("扫码接入",true){beginPairingScan()},LinearLayout.LayoutParams(-1,-2));space(configCard,10)
  configCard.addView(action("粘贴连接配置"){
   val clip=(getSystemService(CLIPBOARD_SERVICE) as ClipboardManager).primaryClip
   val value=if(clip!=null&&clip.itemCount>0)clip.getItemAt(0).coerceToText(this)?.toString()?:"" else ""
   previewPairing(value)
  },LinearLayout.LayoutParams(-1,-2));space(configCard,12)
  manualConfig=column();disclosure(configCard,"手动填写连接配置",manualConfig)
  addressInput=field(manualConfig,"服务器地址","https://你的域名").apply{setText(configuredUrl(prefs().getString("url",null)))}
  tokenInput=field(manualConfig,"设备密钥",if(prefs().contains("token"))"已保存，留空继续使用" else "输入后台生成的 6 位密钥",true)
  manualConfig.addView(text("也可手动填写地址和密钥；密钥重复时，请使用扫码或粘贴配置接入。",12,muted))
  manualConfig.addView(action("保存并启动",true){saveAndStart()},LinearLayout.LayoutParams(-1,-2));space(manualConfig,9);manualConfig.addView(text("密钥只在本机加密保存。请勿使用别人的服务器。",11,muted));configCard.visibility=View.VISIBLE

  updater=AppUpdater(this,card(settingsPage))
  val stopCard=card(settingsPage)
  val advanced=card(settingsPage);advanced.addView(text("高级工具",16,ink,true));space(advanced,10);val management=column();disclosure(advanced,"隔离短信与诊断",management);management.addView(action("查看异常隔离短信"){val entries=Queue(this).use{it.rejected()};val content=text(if(entries.isEmpty())"暂无异常短信"else "最多显示最近50条；全部原文仍保存在本机。\n\n"+entries.joinToString("\n\n────────\n"){"发送方：${it.optString("sender")}\n原因：${it.optString("rejection_reason")}\n正文：${it.optString("body")}"},13,ink).apply{setPadding(dp(18),dp(12),dp(18),dp(12))};android.app.AlertDialog.Builder(this).setTitle("异常短信（未丢弃）").setView(ScrollView(this).apply{addView(content)}).setPositiveButton("关闭",null).show()},LinearLayout.LayoutParams(-1,-2));space(management,10);management.addView(action("重试异常隔离短信"){val count=Queue(this).use{it.rejectedCount()};if(count==0L)show("没有隔离短信。")else android.app.AlertDialog.Builder(this).setTitle("重试 $count 条异常短信？").setMessage("原始短信保留在本机。未解决的格式或长度问题会再次隔离，不会阻塞其他短信。").setNegativeButton("取消",null).setPositiveButton("重试"){_,_->Queue(this).use{it.retryRejected()};Recovery.ensure(this);CollectorService.requestSync();show("已重新加入上传队列。");updateStatus()}.show()},LinearLayout.LayoutParams(-1,-2));space(management,10);stopAction=action("停止采集"){
   android.app.AlertDialog.Builder(this).setTitle("停止采集？").setMessage("停止后，本机新短信不会继续上传。可以随时重新启动。").setNegativeButton("取消",null).setPositiveButton("停止"){_,_->clearPendingStart();prefs().edit().putBoolean("enabled",false).putString("result","用户已停止采集").apply();stopService(Intent(this,CollectorService::class.java));Recovery.cancel(this);show("已停止采集。");updateStatus()}.show()
  };stopCard.addView(stopAction,LinearLayout.LayoutParams(-1,-2));space(management,10);management.addView(action("查看诊断信息"){details.visibility=if(details.visibility==View.VISIBLE)View.GONE else View.VISIBLE},LinearLayout.LayoutParams(-1,-2));details=text("",12,muted).apply{visibility=View.GONE;setPadding(0,dp(12),0,0)};management.addView(details)

  phoneStatus=text("",12,muted);management.addView(phoneStatus)
  settingsPage.addView(text("仅上传启用后新收到的短信全文。",11,muted))
  val navigation=row().apply{setPadding(dp(12),dp(8),dp(12),dp(8));setBackgroundColor(Color.WHITE);elevation=dp(4).toFloat()}
  listOf("运行","检查","设置").forEachIndexed{i,label->val button=action(label){selectTab(i)};button.contentDescription=label;tabButtons.add(button);navigation.addView(button,LinearLayout.LayoutParams(0,-2,1f).apply{leftMargin=dp(3);rightMargin=dp(3)})};shell.addView(navigation)
  setContentView(shell)
  savedInstanceState?.getIntArray("tabScrolls")?.let{it.copyInto(scrollPositions,0,0,minOf(it.size,3))}
  selectTab(savedInstanceState?.getInt("selectedTab")?:if(hasConfig())0 else 2)
  window.decorView.post{if(Build.VERSION.SDK_INT>=30){window.insetsController?.setSystemBarsAppearance(android.view.WindowInsetsController.APPEARANCE_LIGHT_STATUS_BARS or android.view.WindowInsetsController.APPEARANCE_LIGHT_NAVIGATION_BARS,android.view.WindowInsetsController.APPEARANCE_LIGHT_STATUS_BARS or android.view.WindowInsetsController.APPEARANCE_LIGHT_NAVIGATION_BARS)}else{window.decorView.systemUiVisibility=View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR or (if(Build.VERSION.SDK_INT>=26)View.SYSTEM_UI_FLAG_LIGHT_NAVIGATION_BAR else 0)}};updateStatus()
  if(pendingStart&&startPermissionDenied)showRequiredPermissionsMissing()
 }

 private fun value(j:JSONObject,key:String,suffix:String)=if(!j.has(key)||j.isNull(key))"未知" else j.optString(key)+suffix
 private fun flag(j:JSONObject,key:String,yes:String,no:String)=if(!j.has(key)||j.isNull(key))"未知" else if(j.optBoolean(key))yes else no
 private fun network(v:String)=when(v){"wifi"->"Wi-Fi";"cellular"->"移动网络";"ethernet"->"有线网络";"vpn"->"VPN";"none"->"未连接";"other"->"其他网络";else->"未知"}
 private fun serviceLabel(v:String)=when(v){"in_service"->"正常服务";"out_of_service"->"无服务";"emergency_only"->"仅紧急呼叫";"power_off"->"无线电关闭";else->"服务状态未知（权限或系统未提供）"}
 private fun beginPairingScan(){
  if(!packageManager.hasSystemFeature(PackageManager.FEATURE_CAMERA_ANY)){show("此手机没有可用摄像头，请粘贴连接配置或手动填写。");return}
  if(!allowed(Manifest.permission.CAMERA)){requestPermissions(arrayOf(Manifest.permission.CAMERA),cameraRequest);return}
  launchPairingScan()
 }
 private fun launchPairingScan(){
  try{
   com.google.zxing.integration.android.IntentIntegrator(this)
    .setCaptureActivity(PairingCaptureActivity::class.java)
    .setDesiredBarcodeFormats(com.google.zxing.integration.android.IntentIntegrator.QR_CODE)
    .setPrompt("扫描后台“接入手机”二维码；按返回可取消")
    .setBeepEnabled(false).setOrientationLocked(true).setRequestCode(scanRequest).initiateScan()
  }catch(_:Exception){show("无法打开摄像头，请粘贴连接配置或手动填写。")}
 }
 private fun previewPairing(value:String){
  val parsed=ConnectionConfig.parse(value)
  if(parsed==null){show("不是有效的手机连接配置。请扫描后台“接入手机”二维码，或复制手机配置后粘贴；下载链接不能用于接入。","error");return}
  pairingPreview?.dismiss()
  pairingPreview=android.app.AlertDialog.Builder(this).setTitle("确认接入服务器")
   .setMessage("服务器：${parsed.url}\n\n请确认这是你的管理后台。确认后将保存连接配置并请求启动采集。密钥不会显示。")
   .setNegativeButton("取消",null)
   .setPositiveButton("确认并启动"){_,_->
    importedPairing=parsed;addressInput.setText(parsed.url);tokenInput.setText(parsed.token);saveAndStart()
   }.show()
 }
 override fun onActivityResult(requestCode:Int,resultCode:Int,data:Intent?){
  super.onActivityResult(requestCode,resultCode,data)
  if(updater?.installResult(requestCode)==true)return
  if(requestCode==scanRequest){
   if(resultCode!=RESULT_OK){show("扫码已取消，原配置已保留。可粘贴连接配置或手动填写。");return}
   val contents=com.google.zxing.integration.android.IntentIntegrator.parseActivityResult(resultCode,data)?.contents
   if(contents==null)show("未读取到二维码，请重试或粘贴连接配置。")else previewPairing(contents)
  }
 }
 private fun saveAndStart(){try{
  val address=ConnectionConfig.url(addressInput.text.toString());if(address==null){show("请填写 HTTPS 域名，不带 /api 或其他路径。");return}
  val value=tokenInput.text.toString();val p=prefs();if(value.isEmpty()&&!p.contains("token")){show("请先填写后台生成的设备密钥。");return}
  val old=runCatching{p.getString("token",null)?.let{Vault.open(it)}}.getOrNull()
  if(value.isEmpty()&&old==null){show("本机保存的密钥无法读取，请重新填写。");return}
  val deviceId=resolvePairingDeviceId(address,if(value.isEmpty())old!! else value,p.getString("url",null),old,p.getLong("server_device_id",0),importedPairing)
  val changed=deviceId!=p.getLong("server_device_id",0)||p.getString("url",null)!=address||(value.isNotEmpty()&&value!=old)
  if(changed&&(CollectorService.instance!=null||p.getBoolean("enabled",false))){show("请先停止采集，再修改连接配置，避免正在上传的短信发往错误位置。");return}
  val save={
   val edit=p.edit().putString("url",address).putLong("server_device_id",deviceId)
   if(value.isNotEmpty())edit.putString("token",Vault.seal(value))
   if(changed)edit.remove("refresh_ack_generation").remove("refresh_ack").remove("check_code").remove("check_local_at").remove("check_expiry").putString("config_generation",java.util.UUID.randomUUID().toString()).remove("bindings").remove("binding_details").remove("last_success_at").remove("last_success").remove("last_attempt_at").remove("last_received_at").remove("last_sms_upload_at").remove("consecutive_failures").remove("last_error").remove("sync_duration_ms").remove("result").remove("sim_status").putBoolean("sync_in_progress",false)
   if(!edit.commit())throw IllegalStateException("save failed")
   if(changed){latestTelemetry=JSONObject();latestLocalSubs=null;lastTelemetryAt=0;previousSimSnapshot="";connectionErrorVisible=false}
   importedPairing=null;tokenInput.setText("");configCard.visibility=View.VISIBLE;navigateTo(0);show("配置已保存，正在检查权限并启动…","success");requestStart()
  }
  val pending=Queue(this).use{it.count()+it.rejectedCount()}
  if(changed&&pending>0){
   android.app.AlertDialog.Builder(this).setTitle("仍有 $pending 条短信尚未上传（含隔离）")
    .setMessage("为避免把原设备的短信发往新配置，请先恢复原配置并同步。也可以丢弃本机待上传队列后切换；未上传的数据将不会进入后台，手机系统短信不受影响。")
    .setNegativeButton("保留，暂不切换",null)
    .setPositiveButton("丢弃队列并切换"){_,_->Queue(this).use{it.clear()};save()}.show()
  }else save()

 }catch(_:Exception){show("配置未能保存，请检查地址或重新填写设备密钥。")} }
 private fun requestStart(){
  if(startPermissionInFlight)return
  if(!hasConfig()){clearPendingStart();configCard.visibility=View.VISIBLE;navigateTo(2,configCard);show("先填写连接配置，再启动采集。");return}
  pendingStart=true;pendingStartConfig=startConfigIdentity();startPermissionDenied=false

  val supported=startPermissionBatch(Build.VERSION.SDK_INT,packageManager.hasSystemFeature(PackageManager.FEATURE_CAMERA_ANY),emptySet(),false)
  val granted=supported.filter{allowed(it)}.toSet()
  val optionalAsked=prefs().getBoolean("start_optional_permissions_asked",false)
  val missing=startPermissionBatch(Build.VERSION.SDK_INT,packageManager.hasSystemFeature(PackageManager.FEATURE_CAMERA_ANY),granted,optionalAsked)
  if(missing.isEmpty()){startCollector();return}
  startPermissionInFlight=true
  prefs().edit().putBoolean("start_optional_permissions_asked",true).apply()
  show("正在申请启动权限：短信和电话 / SIM 必需；本机号码、通知、摄像头和补充网络状态可拒绝。允许必需项后自动启动，系统可能分多次弹窗。",notify=false)
  try{requestPermissions(missing.toTypedArray(),1)}catch(_:Exception){
   clearPendingStart();prefs().edit().putBoolean("start_optional_permissions_asked",optionalAsked).apply()
   show("未能打开权限申请，请重新点击启动，或到“检查”页打开应用设置。","error")
  }
 }
 private fun startConfigIdentity():String {
  val p=prefs()
  return listOf(p.getString("config_generation","legacy"),p.getString("url",null),p.getLong("server_device_id",0).toString(),p.getString("token",null)).joinToString("|")
 }
 private fun pendingConfigMatches()=pendingStart&&hasConfig()&&pendingStartConfig==startConfigIdentity()
 private fun clearPendingStart(){pendingStart=false;pendingStartConfig=null;startPermissionInFlight=false;startPermissionDenied=false;if(::permissionSettingsAction.isInitialized)permissionSettingsAction.visibility=View.GONE;if(::cancelStartAction.isInitialized)cancelStartAction.visibility=View.GONE}
 private fun showRequiredPermissionsMissing(){
  startPermissionDenied=true
  prefs().edit().putBoolean("enabled",false).apply();stopService(Intent(this,CollectorService::class.java));Recovery.cancel(this)
  val missing=required().filter{!allowed(it)}.map{if(it==Manifest.permission.RECEIVE_SMS)"短信权限未允许"else "电话 / SIM 权限未允许"}
  permissionSettingsAction.visibility=View.VISIBLE
  cancelStartAction.visibility=View.VISIBLE
  show("${missing.joinToString("；")}。采集未启动。可重新点击启动申请；若系统不再弹窗，点下方“打开应用设置”手动允许，返回后自动继续。","error",false)
  updateStatus()
 }
 private fun recommendBackgroundSettings(){
  if(prefs().getBoolean("start_background_guide_shown",false)||isFinishing||isDestroyed)return
  prefs().edit().putBoolean("start_background_guide_shown",true).apply()
  val content=column().apply{setPadding(dp(20),dp(6),dp(20),dp(8))}
  content.addView(text("已请求启动采集。为减少锁屏后被系统停止，建议检查以下设置；可以稍后在“检查”页处理。电池优化可检测，自启动和厂商后台限制需你手动确认。",13))
  space(content,12)
  content.addView(action("打开系统电池优化"){startGuide?.dismiss();if(!launchSettings(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS)))settings()})
  content.addView(action("打开自启动设置"){startGuide?.dismiss();openManufacturerSettings("auto")})
  content.addView(action("打开后台运行设置"){startGuide?.dismiss();openManufacturerSettings("background")})
  startGuide=android.app.AlertDialog.Builder(this).setTitle("建议允许后台持续运行").setView(content).setPositiveButton("稍后设置，继续使用",null).show()
 }
 private fun startCollector(){
  actionAt=System.currentTimeMillis()
  if(!pendingConfigMatches()){clearPendingStart();show("连接配置已变化，请重新点击启动采集。");return}
  if(required().any{!allowed(it)}){showRequiredPermissionsMissing();return}
  clearPendingStart()
  try{prefs().edit().putBoolean("enabled",true).putString("result","正在启动采集服务").apply();Recovery.schedule(this);Recovery.start(this);show("采集服务已请求启动，正在验证后台连接…");recommendBackgroundSettings()}catch(e:Exception){prefs().edit().putBoolean("enabled",false).putString("result","启动被系统拒绝：${e.javaClass.simpleName}").apply();show("系统未允许启动，请检查后台运行权限。")};updateStatus()
 }
 private fun updateStatus(){if(!::heroTitle.isInitialized)return
  val p=prefs();val running=CollectorService.instance!=null;val last=p.getLong("last_success_at",0);val attempt=p.getLong("last_attempt_at",0);val label=connectionState(running,last,attempt,p.getBoolean("sync_in_progress",false),System.currentTimeMillis())
  heroTitle.text=if(running)"● 采集运行中" else if(p.getBoolean("enabled",false))"采集服务待恢复" else if(hasConfig())"采集已暂停" else "准备接入手机"
  heroSubtitle.text=if(running)"接收新短信并上传 · 后台连接单独显示" else if(p.getBoolean("enabled",false))"已安排系统联网补传；点击启动以恢复即时采集。" else if(hasConfig())"点击启动，恢复接收并上传新短信。" else "扫描后台的手机接入二维码，确认后连接。"
  primaryAction.text=if(running)"立即同步" else if(hasConfig())"启动采集" else "扫码接入"
  serverName.text=p.getString("url",null)?.let{runCatching{Uri.parse(it).host}.getOrNull()}?:"尚未设置服务器"
  serverState.text=label;serverState.setTextColor(if(label=="已连接后台")teal else ink)
  lastSync.text=if(last>0)"最近同步  ${timestamp(last)}" else "最近同步  ${p.getString("last_success","尚无成功记录")}"
  val remoteId=p.getLong("remote_refresh_id",0);val remoteGeneration=p.getString("remote_refresh_generation","")?:"";val remoteState=p.getString("remote_refresh_state","")?:""
  if(remoteFeedbackVisible(remoteId,remoteGeneration,p.getString("config_generation","legacy")?:"legacy")){
   val remoteResult=p.getString("remote_refresh_result","")?:"";val remoteFinished=p.getLong("remote_refresh_finished_at",0)
   val remoteTitle=when(remoteState){"success"->"✓ 后台请求：手机状态已上报";"failed"->"! 后台请求：上报失败，将重试";else->"○ 已收到后台请求，正在上报"}
   remoteStatus.text="$remoteTitle\n收到请求：${timestamp(p.getLong("remote_refresh_started_at",0))}${if(remoteFinished>0)"\n结果时间：${timestamp(remoteFinished)}"else ""}\n$remoteResult"
   remoteStatus.setTextColor(if(remoteState=="failed")Color.rgb(175,45,45)else ink)
   val signature="$remoteGeneration:${remoteFeedbackSignature(remoteId,remoteState)}"
   if(activeUi&&remoteSeen.add(signature))show(remoteTitle+"\n"+remoteResult,if(remoteState=="success")"success"else if(remoteState=="failed")"error"else "info",true)
  }else remoteStatus.text="后台请求：尚无本配置的手机上报记录\n点击后台请求后，手机在线且采集运行时会处理；通常约 15 秒开始。"
  queueCount.setTextColor(if(Queue(this).use{it.rejectedCount()}>0)Color.rgb(175,45,45)else muted)
  smsOverview.text="最近收到：${if(p.getLong("last_received_at",0)>0)timestamp(p.getLong("last_received_at",0))else "尚未收到"}\n最近短信上传：${if(p.getLong("last_sms_upload_at",0)>0)timestamp(p.getLong("last_sms_upload_at",0))else "尚无成功记录"}"
  queueCount.text=Queue(this).use{"待上传 ${it.count()} 条 · 隔离 ${it.rejectedCount()} 条"}
  updateChecklist()
  stopAction.visibility=if(running||p.getBoolean("enabled",false))View.VISIBLE else View.GONE
  val result=p.getString("result","尚未启动")?:"尚未启动"
  if(activeUi&&!samplingTelemetry&&System.currentTimeMillis()-lastTelemetryAt>10000){
   samplingTelemetry=true
   val sampledGeneration=p.getString("config_generation","legacy")
   val sampledNumberAccess=numberHintsAllowed()
   val sampledConsentRevision=numberConsentRevision
   telemetryWorker.execute{
    val sample=TelemetryCollector(this).collect()
    val snapshot=sample.optJSONObject("telemetry")?:JSONObject()
    val localSubs=sample.optJSONArray("subscriptions")
    handler.post{samplingTelemetry=false;if(!isDestroyed&&prefs().getString("config_generation","legacy")==sampledGeneration&&phoneHintSampleCurrent(sampledNumberAccess,numberHintsAllowed(),sampledConsentRevision,numberConsentRevision)){latestTelemetry=snapshot;latestLocalSubs=localSubs;lastTelemetryAt=System.currentTimeMillis();if(activeUi)updateStatus()}}
   }
  }
  val telemetry=latestTelemetry
  phoneStatus.text="电量 ${value(telemetry,"battery_percent","%")} · ${flag(telemetry,"charging","充电中","未充电")} · ${value(telemetry,"battery_temp_c","℃")}\n网络 ${network(telemetry.optString("network_transport"))} · ${flag(telemetry,"internet_validated","互联网已验证","互联网未验证")} "
  details.text="电量：${value(telemetry,"battery_percent","%")} / 网络：${network(telemetry.optString("network_transport"))}\n省电模式：${flag(telemetry,"power_save","开启","关闭")} / 电池优化豁免：${flag(telemetry,"battery_optimization_ignored","已允许","未允许")}\n上次耗时：${value(telemetry,"sync_duration_ms"," ms")} / 连续失败：${p.getInt("consecutive_failures",0)}\n版本：${BuildConfig.VERSION_NAME}\n服务：${if(running)"运行中" else "未运行"}\n最近尝试：${timestamp(attempt)}\n最近结果：$result\n系统：${Build.MANUFACTURER} ${Build.MODEL} / Android ${Build.VERSION.RELEASE}"
  val busy=p.getBoolean("sync_in_progress",false)
  if(actionAt>0&&!busy){
   if(last>=actionAt&&last>actionResultAt){actionResultAt=last;actionAt=0;connectionErrorVisible=false;show("✓ 配置已生效，采集运行中，后台连接成功。最近同步：${timestamp(last)}","success")}
   else if(attempt>=actionAt&&attempt>last&&attempt>actionResultAt){actionResultAt=attempt;actionAt=0;show("连接失败：$result\n配置已保留，请检查网络、地址或密钥后点“立即同步”。","error")}
   else if(!running&&p.getBoolean("enabled",false).not()&&result.startsWith("服务启动失败")){show(result,"error",false)}
   else if(last<actionAt&&System.currentTimeMillis()-actionAt>20000&&attempt<actionAt){show("尚未获得后台连接结果，请检查采集服务及系统后台限制。","error",false)}
  }
  if(running&&!busy&&attempt>last){connectionErrorVisible=true;serverState.text="连接失败 · $result";if(actionAt==0L)show("配置已保存，但后台连接失败：$result\n请检查网络、地址和密钥后重试。","error",false)}
  else if(running&&!busy&&last>0&&last>=attempt&&connectionErrorVisible){connectionErrorVisible=false;show("✓ 后台连接已恢复，最近同步：${timestamp(last)}","success",false)}
  hero.background=shape(if(!running)Color.rgb(85,102,120)else if(attempt>last&&!busy)Color.rgb(156,79,30)else teal)
  details.append("\n最久等待上传：${value(telemetry,"oldest_pending_age_seconds"," 秒")}\n最近收到短信：${timestamp(p.getLong("last_received_at",0))}\n最近短信上传：${timestamp(p.getLong("last_sms_upload_at",0))}")
  runCatching{
   val networkDetails=latestLocalSubs?:JSONArray(p.getString("sim_status","[]"))
   for(i in 0 until networkDetails.length()){
    val sim=networkDetails.getJSONObject(i)
    details.append("\n\n卡槽 ${sim.optInt("slot",i+1)}\n接入运营商：${value(sim,"network_operator","")}\n信号：${value(sim,"signal_dbm"," dBm")}（缓存 ${value(sim,"signal_age_seconds"," 秒")}）\n漫游：${flag(sim,"roaming","漫游中","未报告漫游")}\n网络制式：${value(sim,"radio_type","")}")
    if(sim.optString("service_state_reason")=="permission_denied")details.append("\n服务状态受权限限制，可选网络状态权限可能有帮助。")
   }
  }
  val raw=latestLocalSubs?.toString()?:p.getString("sim_status","[]")?:"[]";val binds=p.getString("binding_details","[]")?:"[]";val versions=p.getString("bindings","{}")?:"{}";val snapshot=raw+binds+versions+allowed(Manifest.permission.READ_PHONE_STATE)
  if(snapshot!=previousSimSnapshot){previousSimSnapshot=snapshot;simList.removeAllViews();try{
   val sims=JSONArray(raw);val binding=JSONArray(binds);val cached=JSONObject(versions)
   if(sims.length()==0)for(slot in 1..2){simList.addView(text("SIM $slot · 等待读取",14,ink,true));simList.addView(text("${if(allowed(Manifest.permission.READ_PHONE_STATE))"启动并同步后确认卡槽"else "请在检查页开启电话权限"}",12,muted));space(simList,12)}
   for(i in 0 until sims.length()){
    val sim=sims.getJSONObject(i);if(!sim.has("subscription_id")){simList.addView(text("需要电话权限才能识别 SIM 卡。",13,muted));continue}
    val sub=sim.getInt("subscription_id");var info:JSONObject?=null;for(j in 0 until binding.length())if(binding.getJSONObject(j).optInt("subscription_id",-1)==sub)info=binding.getJSONObject(j)
    val slot=sim.optInt("slot",0);val carrier=sim.optString("carrier","运营商未上报");val item=column().apply{background=shape(pageBackground);setPadding(dp(14),dp(13),dp(14),dp(13))}
    val boundPhone=info?.optString("phone")?.takeIf{it.isNotBlank()&&it!="null"}
    val hint=sim.optString("phone_hint").takeIf{sim.optString("phone_hint_source")=="android_sim"}
    val hasBinding=info!=null||cached.has(sub.toString())
    item.addView(text(simPhoneHeading(boundPhone,hint,hasBinding),18,ink,true));space(item,5)
    item.addView(text("SIM ${if(slot>0)slot else i+1} · $carrier · ${if(hasBinding)"已在后台绑定"else "未确认绑定"}",12,muted));space(item,4)
    val serviceSummary=mutableListOf(serviceLabel(sim.optString("service_state")),"信号 ${value(sim,"signal_level","/4")}${if(sim.optLong("signal_age_seconds",0)>180)"（旧缓存）" else ""}")
    if(!sim.isNull("roaming"))serviceSummary.add(flag(sim,"roaming","漫游中","未报告漫游"))
    if(!sim.isNull("radio_type"))serviceSummary.add(value(sim,"radio_type",""))
    item.addView(text(serviceSummary.joinToString(" · "),12,muted));space(item,4)
    item.addView(text(info?.optString("shop")?.takeIf{it.isNotBlank()&&it!="null"}?:if(hasBinding)"店铺信息待后台同步"else "尚未绑定 · 请在后台确认号码",12,muted));space(item,6);simList.addView(item,LinearLayout.LayoutParams(-1,-2).apply{bottomMargin=dp(9)})
   }
  }catch(_:Exception){simList.addView(text("卡槽信息尚未就绪，请重新同步。",13,muted))}}
 }
 override fun onResume(){super.onResume();updater?.resume();activeUi=true;handler.removeCallbacks(refresh);handler.post(refresh)
  if(pendingStart&&!startPermissionInFlight){
   if(!pendingConfigMatches()){clearPendingStart();show("连接配置已变化，请重新点击启动采集。",notify=false)}
   else if(required().all{allowed(it)})startCollector()
  }
 }
 override fun onWindowFocusChanged(hasFocus:Boolean){super.onWindowFocusChanged(hasFocus);updater?.focusChanged(hasFocus)}
 override fun onPause(){updater?.pause();activeUi=false;handler.removeCallbacks(refresh);super.onPause()}
 override fun onDestroy(){pairingPreview?.dismiss();startGuide?.dismiss();updater?.close();receiveCheck?.close();telemetryWorker.shutdownNow();super.onDestroy()}
 override fun onSaveInstanceState(out:Bundle){scrollPositions[selectedTab]=screenScroll.scrollY;out.putBoolean("recommendedExpanded",recommendedExpanded);out.putBoolean("optionalExpanded",optionalExpanded);out.putStringArrayList("expandedDisclosures",ArrayList(expandedDisclosures));out.putInt("selectedTab",selectedTab);out.putIntArray("tabScrolls",scrollPositions);out.putBoolean("pendingStart",pendingStart);out.putBoolean("startPermissionInFlight",startPermissionInFlight);out.putBoolean("startPermissionDenied",startPermissionDenied);out.putString("pendingStartConfig",pendingStartConfig);out.putBoolean("legacyNumberConsent",legacyNumberConsent?.isChecked?:false);super.onSaveInstanceState(out)}
 override fun onRequestPermissionsResult(r:Int,p:Array<out String>,g:IntArray){
  super.onRequestPermissionsResult(r,p,g)
  if(r==cameraRequest){if(g.isNotEmpty()&&g.all{it==PackageManager.PERMISSION_GRANTED})launchPairingScan()else show("未开启摄像头权限，原配置已保留。请粘贴连接配置或手动填写。");return}
  if(r==1&&pendingStart&&startPermissionInFlight){
   startPermissionInFlight=false;setupSnapshot="";lastTelemetryAt=0
   if(!pendingConfigMatches()){clearPendingStart();show("连接配置已变化，请重新点击启动采集。",notify=false);return}
   when(startPermissionOutcome(required().all{allowed(it)},p.isNotEmpty()&&g.isNotEmpty()&&p.size==g.size)){
    StartPermissionOutcome.START->if(activeUi)startCollector()
    StartPermissionOutcome.REQUIRED_MISSING->showRequiredPermissionsMissing()
    StartPermissionOutcome.CANCELED->{clearPendingStart();show("权限申请已取消，采集未启动。可重新点击启动。",notify=false)}
   }
   updateStatus()
  }
  if(r==2){lastTelemetryAt=0;CollectorService.requestSync();updateStatus()}
  if(r==3){setupSnapshot="";lastTelemetryAt=0;CollectorService.requestSync();updateStatus();if(g.any{it!=PackageManager.PERMISSION_GRANTED})show("权限尚未开启。如果系统不再弹窗，请在该项的应用设置里手动允许。")}
 }
}
