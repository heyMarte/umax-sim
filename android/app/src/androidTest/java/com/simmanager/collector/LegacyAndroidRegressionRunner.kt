package com.simmanager.collector

import android.app.Activity
import android.app.Instrumentation
import android.content.Intent
import android.database.sqlite.SQLiteDatabase
import android.os.Build
import android.os.Bundle
import android.view.View
import android.view.ViewGroup
import android.widget.TextView
import org.json.JSONObject

/** Dedicated emulator only: exercise the real old-runtime resource hierarchy and preserve upgrade data. */
class LegacyAndroidRegressionRunner:Instrumentation(){
 override fun onCreate(args:Bundle?){super.onCreate(args);start()}
 override fun onStart(){
  val out=Bundle()
  try{
   val c=targetContext;val p=c.getSharedPreferences("config",0)
   p.edit().clear().putBoolean("enabled",false).commit();Recovery.cancel(c);c.stopService(Intent(c,CollectorService::class.java))
   c.deleteDatabase("queue.db")
   val file=c.getDatabasePath("queue.db");file.parentFile?.mkdirs()
   val payload=JSONObject().put("event_id","legacy-kept").put("sender","legacy-fixture").put("body","Your test OTP is 123456").put("subscription_id",7).put("binding_version","old-binding-must-stay")
   SQLiteDatabase.openOrCreateDatabase(file,null).let{db->try{db.execSQL("CREATE TABLE queue(id TEXT PRIMARY KEY,payload TEXT NOT NULL,enqueued_at INTEGER NOT NULL)");db.execSQL("INSERT INTO queue VALUES(?,?,?)",arrayOf("legacy-kept",payload.toString(),1000L));db.version=2}finally{db.close()}}
   val sealed=Vault.seal("Legacy key fixture 中文")
   p.edit().putString("url","https://legacy-regression.invalid").putString("token",sealed).putLong("server_device_id",83).putString("config_generation","legacy-api-test").commit()
   // This exact call throws ClassCastException on API26/27/28 in the old APK.
   Queue(c).use{q->check(q.count()==1L);check(q.pending().single().getString("binding_version")=="old-binding-must-stay");check(q.rejectedCount()==0L)}
   repeat(2){
    val a=startActivitySync(Intent(c,MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) as MainActivity
    runOnMainSync{
     fun texts(v:View):List<String> = when(v){is TextView->listOf(v.text.toString());is ViewGroup->(0 until v.childCount).flatMap{texts(v.getChildAt(it))};else->emptyList()}
     val ui=texts(a.window.decorView);check(ui.any{it.contains("Umax SIM 工作台")});check(ui.any{it.contains("待上传 1 条")});check(ui.any{it.contains("设备密钥")})
     a.finish()
    };waitForIdleSync()
   }
   check(p.getString("token","")==sealed);check(Vault.open(sealed)=="Legacy key fixture 中文");check(p.getLong("server_device_id",0)==83L)
   Queue(c).use{q->check(q.count()==1L);q.quarantine("legacy-kept","synthetic malformed input");check(q.rejectedCount()==1L);q.retryRejected();check(q.count()==1L&&q.rejectedCount()==0L);q.ack("legacy-kept");check(q.count()==0L)}
   val sample=TelemetryCollector(c).collect();val telemetry=sample.getJSONObject("telemetry")
   // The empty emergency fallback must not count as a successful compatibility sample.
   check(telemetry.getString("app_version")==c.packageManager.getPackageInfo(c.packageName,0).versionName)
   check(telemetry.getInt("android_sdk")==Build.VERSION.SDK_INT)
   check(telemetry.has("battery_percent")&&telemetry.has("permissions"))
   check(telemetry.getJSONObject("permissions").getBoolean("phone")==
    (c.checkSelfPermission(android.Manifest.permission.READ_PHONE_STATE)==android.content.pm.PackageManager.PERMISSION_GRANTED))
   check(sample.has("subscriptions")&&sample.has("subscriptions_status"))
   // No-configuration startup is separate from the fresh-install launcher probe.
   p.edit().clear().putBoolean("enabled",false).commit()
   val fresh=startActivitySync(Intent(c,MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) as MainActivity
   runOnMainSync{fresh.finish()};waitForIdleSync()
   out.putString("stream","PASS API ${Build.VERSION.SDK_INT} / Android ${Build.VERSION.RELEASE}: initial/repeat activity launch, v2 queue upgrade, pending and quarantine operations, encrypted key/config retained, nonempty real telemetry and no-config startup\n")
   finish(Activity.RESULT_OK,out)
  }catch(t:Throwable){out.putString("stream","FAIL API ${Build.VERSION.SDK_INT}: "+t.stackTraceToString());finish(Activity.RESULT_CANCELED,out)}
 }
}
