package com.simmanager.collector
import android.app.*
import android.content.Intent
import android.os.IBinder
import android.os.PowerManager
import android.telephony.SubscriptionManager
import org.json.JSONArray
import org.json.JSONObject
import java.net.URL
import javax.net.ssl.HttpsURLConnection
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import java.util.concurrent.RejectedExecutionException
import java.util.Date

import android.content.Context
class Uploader(private val context:Context,private val alive:()->Boolean,private val sample:(()->JSONObject)?=null,private val transport:((String,JSONObject)->JSONObject)?=null){
 companion object{private val uploadLock=java.util.concurrent.locks.ReentrantLock()}
 private class Connection(val endpoint:String,val token:String,val generation:String,val deviceId:Long)
 private fun getSharedPreferences(n:String,m:Int)=context.getSharedPreferences(n,m)
 private fun getSystemService(n:String)=context.getSystemService(n)
 private fun current(c:Connection):Boolean {
  val p=getSharedPreferences("config",0)
  return c.deviceId==p.getLong("server_device_id",0)&&c.endpoint==p.getString("url","")&&runCatching{c.token==Vault.open(p.getString("token","")?:"")}.getOrDefault(false)&&alive()&&syncGenerationMatches(c.generation,p.getString("config_generation","legacy")?:"legacy",p.getBoolean("enabled",false))
 }
 private fun post(c:Connection,path:String,data:JSONObject):JSONObject {
  if(!current(c))throw InterruptedException()
  transport?.let{return it(path,data)}
  val connection=URL(c.endpoint+"/api/device/"+path).openConnection() as HttpsURLConnection
  connection.requestMethod="POST";connection.connectTimeout=15000;connection.readTimeout=15000
  connection.instanceFollowRedirects=false;connection.doOutput=true
  connection.setRequestProperty("Content-Type","application/json");connection.setRequestProperty("Authorization",deviceAuthorization(c.token))
  if(c.deviceId>0)connection.setRequestProperty("X-Device-Id",c.deviceId.toString())
  try{
   connection.outputStream.use{it.write(data.toString().toByteArray())}
   if(connection.responseCode!=200)throw IllegalStateException("HTTP ${connection.responseCode}")
   return JSONObject(connection.inputStream.bufferedReader().use{it.readText()})
  }finally{connection.disconnect()}
 }
 private fun snapshot():ObservedTelemetry = sample?.let{sampler->SimLifecycle.locked {
  val generation=getSharedPreferences("config",0).getString("config_generation","legacy")?:"legacy"
  val raw=sampler();ObservedTelemetry(raw,SimLifecycle.observe(context,raw,generation))
 }}?:TelemetryCollector(context).collectSnapshot()
 fun sync(requestId:Long?=null){
  if(!uploadLock.tryLock())return
  try { syncLocked(requestId) } finally {uploadLock.unlock()}
 }
 private fun syncLocked(requestId:Long?){
  val started=android.os.SystemClock.elapsedRealtime()
  val p=getSharedPreferences("config",0);if(!p.getBoolean("enabled",false)||!alive())return
  var captured:Connection?=null
  var uploaded=false
  var requestedCompleted=false
  val wake=(getSystemService(Context.POWER_SERVICE) as PowerManager).newWakeLock(PowerManager.PARTIAL_WAKE_LOCK,"sim:upload")
  try{
   val generation=p.getString("config_generation","legacy")?:"legacy"
   val c=Connection(p.getString("url","")?:"",Vault.open(p.getString("token","")?:""),generation,p.getLong("server_device_id",0));captured=c
   if(!current(c))return
   if(requestId!=null&&requestId>0)OperationFeedback.remote(context,requestId,c.generation,"running","已收到后台请求，正在采样并上报手机状态…")
   p.edit().putLong("last_attempt_at",System.currentTimeMillis()).putBoolean("sync_in_progress",true).apply()
   wake.acquire(90000)
   val observed=snapshot();val sample=observed.payload
   val subs=sample.getJSONArray("subscriptions")
   if(!current(c))return
   p.edit().putString("sim_status",subs.toString()).apply()
   if(requestId!=null)sample.put("refresh_request_id",requestId)
   val heartbeat=post(c,"heartbeat",sample)
   if(!current(c))return
   if(!heartbeat.optBoolean("snapshot_accepted",true))throw IllegalStateException("snapshot_rejected")
   // A response can arrive after a replacement while the request was in flight.
   snapshot()
   if(!current(c))return
   SimLifecycle.acceptBindings(context,observed.observation,heartbeat.getJSONArray("bindings"))
   if(requestId!=null&&current(c)){p.edit().putLong("refresh_ack",requestId).putString("refresh_ack_generation",c.generation).apply();requestedCompleted=true;OperationFeedback.remote(context,requestId,c.generation,"success","手机状态已上报后台。这是状态同步，不会自动收到短信或查询余额。短信上传请另看同步结果。")}
   Queue(context).use{q->
    val batch=q.pending()
    if(batch.isNotEmpty()){
     fun send(items:List<JSONObject>){
      val response=post(c,"messages",JSONObject().put("sim_observation_version",1).put("events",JSONArray(items)))
      if(!current(c))return
      val accepted=response.getJSONArray("accepted")
      for(i in 0 until accepted.length())q.ack(accepted.getString(i))
      if(accepted.length()>0){uploaded=true;p.edit().putLong("last_sms_upload_at",System.currentTimeMillis()).apply()}
     }
     try{send(batch)}catch(e:IllegalStateException){
      if(e.message!="HTTP 422"&&e.message!="HTTP 413")throw e
      for(event in batch){if(!current(c))return
       try{send(listOf(event))}catch(single:IllegalStateException){
        if(single.message!="HTTP 422"&&single.message!="HTTP 413")throw single
        q.quarantine(event.getString("event_id"),"服务器拒绝此条短信（${single.message}），已隔离保留")
       }
      }
     }
    }
   }
   if(current(c))p.edit().putLong("last_success_at",System.currentTimeMillis()).putString("last_success",Date().toString()).putString("result","同步成功").putInt("consecutive_failures",0).putString("last_error","").putLong("sync_duration_ms",android.os.SystemClock.elapsedRealtime()-started).apply()
   if(uploaded&&current(c))runCatching{post(c,"heartbeat",TelemetryCollector(context).collect())}
  }catch(e:Exception){
   if(captured?.let{current(it)}==true){
    val reason=when(e){
     is javax.net.ssl.SSLException->"HTTPS 证书验证失败，请检查域名和证书"
     is java.net.UnknownHostException->"域名无法解析，请检查地址及手机网络"
     is java.net.SocketTimeoutException->"连接超时，请检查手机网络及服务器"
     is InterruptedException->"同步已停止"
     is IllegalStateException->when(e.message){"HTTP 401"->"设备密钥错误、已停用或密钥重复，请检查后台设备，或扫码 / 粘贴连接配置重新接入";"HTTP 404"->"接口不存在，请检查域名和后台伪静态";"HTTP 403"->"服务器或防火墙拒绝请求";"snapshot_rejected"->"SIM 状态已变更，后台未接纳旧快照；稍后会重新采样同步";else->"服务器响应异常：${e.message?.takeIf{it.startsWith("HTTP ")}?:e.javaClass.simpleName}"}
     else->"同步失败：${e.javaClass.simpleName}，请检查网络和后台配置"
    }
    if(requestId!=null&&!requestedCompleted)OperationFeedback.remote(context,requestId,captured!!.generation,"failed",reason+"。本次状态上报未完成，联网后会重试。")
    p.edit().putString("result",reason).putString("last_error",reason.take(300)).putInt("consecutive_failures",p.getInt("consecutive_failures",0)+1).apply()
   }else if(captured==null&&alive()){p.edit().putString("result","本机连接配置无法读取，请重新填写设备密钥").apply()}
  }finally{if(captured?.let{current(it)}==true)p.edit().putBoolean("sync_in_progress",false).putLong("sync_duration_ms",android.os.SystemClock.elapsedRealtime()-started).apply();if(wake.isHeld)wake.release()}
 }
}
