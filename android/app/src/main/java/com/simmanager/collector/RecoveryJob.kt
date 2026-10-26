package com.simmanager.collector
import android.app.job.*
import android.content.*
import android.os.Build
import java.util.concurrent.Executors
object Recovery {
 fun schedule(c:Context){
  val js=c.getSystemService(JobScheduler::class.java)
  val name=ComponentName(c,RecoveryJob::class.java)
  val pending=js.allPendingJobs.map{it.id}.toSet()
  if(3201 !in pending)js.schedule(JobInfo.Builder(3201,name).setRequiredNetworkType(JobInfo.NETWORK_TYPE_ANY).setPersisted(true).setPeriodic(15*60*1000L).build())
  if(3202 !in pending)js.schedule(JobInfo.Builder(3202,name).setRequiredNetworkType(JobInfo.NETWORK_TYPE_ANY).setMinimumLatency(1000).build())
 }
 fun cancel(c:Context){val js=c.getSystemService(JobScheduler::class.java);js.cancel(3201);js.cancel(3202)}
 fun start(c:Context){
  val intent=Intent(c,CollectorService::class.java)
  if(Build.VERSION.SDK_INT>=26)c.startForegroundService(intent) else c.startService(intent)
 }
 fun ensure(c:Context){
  if(!c.getSharedPreferences("config",0).getBoolean("enabled",false))return
  schedule(c)
  if(CollectorService.instance==null)try{start(c)}catch(_:Exception){c.getSharedPreferences("config",0).edit().putString("result","系统限制即时启动，已安排联网补传；打开App可立即启动").apply()}
 }
}
class RecoveryJob:JobService(){
 @Volatile private var active=true
 private val worker=Executors.newSingleThreadExecutor()
 override fun onStartJob(p:JobParameters):Boolean{
  if(!getSharedPreferences("config",0).getBoolean("enabled",false)){Recovery.cancel(this);return false}
  active=true;worker.execute{try{Uploader(this,alive={active}).sync()}finally{if(active)jobFinished(p,false)}};return true
 }
 override fun onStopJob(p:JobParameters):Boolean{active=false;return getSharedPreferences("config",0).getBoolean("enabled",false)}
 override fun onDestroy(){active=false;worker.shutdownNow();super.onDestroy()}
}
