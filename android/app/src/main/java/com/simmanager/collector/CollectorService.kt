package com.simmanager.collector
import android.app.*
import android.content.Intent
import android.os.Build
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

class CollectorService:Service(){
 companion object {
  @Volatile var instance:CollectorService?=null
  fun requestSync(){instance?.let{try{it.executor.execute{it.sync()}}catch(_:RejectedExecutionException){}}}
 }
 private val executor=Executors.newSingleThreadScheduledExecutor()
 private var subscriptionsListener:SubscriptionManager.OnSubscriptionsChangedListener?=null
 @Suppress("DEPRECATION")
 private fun watchSubscriptions(){
  // Service.onCreate runs on the main Looper: required by the API26-29 listener.
  val listener=object:SubscriptionManager.OnSubscriptionsChangedListener(){
   override fun onSubscriptionsChanged(){
    if(instance!==this@CollectorService)return
    // Sample now, even when a network upload is busy, so remove/reinsert is observed.
    runCatching{SimLifecycle.locked{SimLifecycle.observe(this@CollectorService,TelemetryCollector(this@CollectorService).collectSubscriptions())}}
    requestSync()
   }
  }
  subscriptionsListener=listener
  runCatching{
   val manager=getSystemService(SubscriptionManager::class.java)
   if(android.os.Build.VERSION.SDK_INT>=30)manager.addOnSubscriptionsChangedListener(mainExecutor,listener)
   else manager.addOnSubscriptionsChangedListener(listener)
  }
 }
 private class Connection(val endpoint:String,val token:String,val generation:String)
 override fun onBind(i:Intent?):IBinder?=null
 override fun onCreate(){
  super.onCreate();val p=getSharedPreferences("config",0)
  try{
   val nm=getSystemService(NotificationManager::class.java)
   if(Build.VERSION.SDK_INT>=26)nm.createNotificationChannel(NotificationChannel("collector","Umax SIM 工作台运行状态",NotificationManager.IMPORTANCE_LOW))
   val pending=PendingIntent.getActivity(this,0,Intent(this,MainActivity::class.java),PendingIntent.FLAG_IMMUTABLE)
   val notification=if(Build.VERSION.SDK_INT>=26)Notification.Builder(this,"collector") else Notification.Builder(this)
   startForeground(1,notification.setSmallIcon(android.R.drawable.stat_notify_sync).setContentTitle("Umax SIM 工作台").setContentText("采集已启用 · 新短信自动同步到你的管理后台").setContentIntent(pending).build())
   instance=this;p.edit().putBoolean("sync_in_progress",false).putString("result","服务已启动，正在连接服务器").apply()
   watchSubscriptions()
   executor.scheduleWithFixedDelay({sync()},0,60,TimeUnit.SECONDS)
   executor.scheduleWithFixedDelay({poll()},15,15,TimeUnit.SECONDS)
  }catch(e:Exception){instance=null;p.edit().putBoolean("enabled",false).putString("result","服务启动失败：${e.javaClass.simpleName}，请检查系统后台运行权限").apply();stopSelf()}
 }
 override fun onStartCommand(i:Intent?,flags:Int,id:Int):Int=START_STICKY
 private fun poll(){
  val client=DeviceClient(this){instance===this}
  runCatching{val result=client.post("poll",JSONObject());val id=result.optLong("refresh_request_id",0);val p=getSharedPreferences("config",0);if(client.current()&&pendingRefresh(id,refreshAckForGeneration(p.getLong("refresh_ack",0),p.getString("refresh_ack_generation",null),p.getString("config_generation","legacy"))))Uploader(this,alive={instance===this&&client.current()}).sync(id)}
 }
 private fun sync(){Uploader(this,alive={instance===this}).sync()}
 override fun onDestroy(){
  if(instance===this)instance=null
  subscriptionsListener?.let{listener->runCatching{getSystemService(SubscriptionManager::class.java).removeOnSubscriptionsChangedListener(listener)}}
  subscriptionsListener=null;executor.shutdownNow();super.onDestroy()
 }
}
