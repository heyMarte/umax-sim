package com.simmanager.collector

import android.app.Activity
import android.app.Instrumentation
import android.app.NotificationManager
import android.app.job.JobScheduler
import android.content.Intent
import android.os.Build
import android.os.Bundle
import android.os.SystemClock
import com.google.zxing.BarcodeFormat
import com.google.zxing.BinaryBitmap
import com.google.zxing.MultiFormatReader
import com.google.zxing.RGBLuminanceSource
import com.google.zxing.common.HybridBinarizer
import com.google.zxing.qrcode.QRCodeWriter

/** Dedicated emulator only: real Android6 APIs, with collection disabled to prevent network traffic. */
class Android6CompatibilityRunner:Instrumentation(){
 override fun onCreate(args:Bundle?){super.onCreate(args);start()}
 override fun onStart(){val out=Bundle();try{
  val c=targetContext;val p=c.getSharedPreferences("config",0)
  p.edit().clear().putBoolean("enabled",false).putString("url","https://android6-regression.invalid").commit()
  Recovery.cancel(c);c.stopService(Intent(c,CollectorService::class.java));waitForIdleSync()
  check(c.packageManager.getApplicationIcon(c.packageName).intrinsicWidth>0){"Launcher icon could not inflate"}
  val a=startActivitySync(Intent(c,MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) as MainActivity
  // Package parsing / auth exercises the desugared java.util.Base64 implementation on the old VM.
  val qr="SIMMANAGER2\nhttps://android6-regression.invalid\n"+java.util.Base64.getEncoder().encodeToString("Android6 中文 key".toByteArray())+"\n23"
  check(ConnectionConfig.parse(qr)?.token=="Android6 中文 key")
  check(deviceAuthorization("Android6 中文 key").startsWith("DeviceKey "))
  // The actual ZXing encoder and decoder must both load on API23, not only the capture Activity.
  val matrix=QRCodeWriter().encode(qr,BarcodeFormat.QR_CODE,320,320)
  val pixels=IntArray(matrix.width*matrix.height){i->if(matrix[i%matrix.width,i/matrix.width])0xff000000.toInt()else 0xffffffff.toInt()}
  val decoded=MultiFormatReader().decode(BinaryBitmap(HybridBinarizer(RGBLuminanceSource(matrix.width,matrix.height,pixels))))
  check(decoded.text==qr)
  // Settings must be readable on pre-O; this also checks the modern package permission guard.
  canInstallUpdates(c)
  val sample=TelemetryCollector(c).collect();val telemetry=sample.getJSONObject("telemetry")
  check(telemetry.getInt("android_sdk")==Build.VERSION.SDK_INT)
  check(telemetry.has("permissions")&&telemetry.has("battery_percent"))
  if(Build.VERSION.SDK_INT<26)check(!telemetry.getJSONObject("permissions").getBoolean("phone_numbers"))
  val subs=sample.getJSONArray("subscriptions")
  if(Build.VERSION.SDK_INT==23)for(i in 0 until subs.length()){
   val sim=subs.getJSONObject(i)
   check(sim.getString("service_state")=="unknown")
   check(sim.getBoolean("subscription_active"))
   check(!sim.isNull("sim_token")){"Active legacy card lacks generation token"}
   for(key in listOf("sim_state","network_operator","network_country","sim_operator","radio_type","roaming","signal_level","signal_dbm"))check(sim.isNull(key)){"Default SIM data leaked into $key"}
   check(sim.isNull("phone_hint")){"No legacy number consent was given"}
  }
  // JobService.onStartJob also runs on main. Keep schedule + inspection in one main turn,
  // before the disabled-collector callback can legitimately cancel the scheduled jobs.
  runOnMainSync{
   Recovery.schedule(c);Recovery.schedule(c)
   val jobs=c.getSystemService(JobScheduler::class.java).allPendingJobs
   check(jobs.count{it.id==3201}==1&&jobs.count{it.id==3202}==1){"Recovery must schedule each job exactly once"}
  }
  val manager=c.getSystemService(NotificationManager::class.java)
  OperationFeedback.notify(c,"Android compatibility fixture","Synthetic operation result",49023)
  fun awaitNotification(id:Int,timeout:Long=5000){
   val notificationDeadline=SystemClock.elapsedRealtime()+timeout
   while(manager.activeNotifications.none{it.id==id}&&SystemClock.elapsedRealtime()<notificationDeadline)Thread.sleep(50)
   check(manager.activeNotifications.any{it.id==id}){"Notification $id was not posted; notificationsEnabled=${androidx.core.app.NotificationManagerCompat.from(c).areNotificationsEnabled()}"}
  }
  // NotificationManagerService enqueues on its own Handler; notify() does not wait for publication.
  awaitNotification(49023)
  runOnMainSync{Recovery.start(a)}
  val deadline=SystemClock.elapsedRealtime()+5000
  while(CollectorService.instance==null&&SystemClock.elapsedRealtime()<deadline)Thread.sleep(50)
  check(CollectorService.instance!=null){"Foreground service failed: ${p.getString("result","")}"}
  // Android12+ normally defers foreground-service notification visibility by about 10 seconds.
  awaitNotification(1,if(Build.VERSION.SDK_INT>=31)20000 else 5000)
  check(!p.getBoolean("enabled",false)){"Fixture must never enable network collection"}
  runOnMainSync{c.stopService(Intent(c,CollectorService::class.java));a.finish()};waitForIdleSync()
  Recovery.cancel(c);manager.cancel(49023)
  out.putString("stream","PASS API ${Build.VERSION.SDK_INT}: launcher, desugared Unicode pairing/auth, real QR decode, update settings gate, nonempty telemetry, conservative per-card data, legacy number consent, idempotent recovery jobs, operation notification, foreground service notification; collection remained disabled\n")
  finish(Activity.RESULT_OK,out)
 }catch(t:Throwable){out.putString("stream","FAIL API ${Build.VERSION.SDK_INT}: "+t.stackTraceToString());finish(Activity.RESULT_CANCELED,out)}}
}
