package com.simmanager.collector

import android.Manifest
import android.app.Activity
import android.app.Instrumentation
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Bundle
import android.view.View
import android.widget.TextView
import android.widget.CheckBox

/** Disposable emulator only. Seed mode never launches an Activity or a network operation. */
class StartPermissionsRegressionRunner:Instrumentation(){
 private var seedOnly=false
 override fun onCreate(args:Bundle?){seedOnly=args?.getString("mode")=="seed";super.onCreate(args);start()}
 override fun onStart(){val out=Bundle();try{
  val c=targetContext;val p=c.getSharedPreferences("config",0)
  if(seedOnly){
   check(p.edit().clear().putString("url","https://permission-start.invalid").putString("token",Vault.seal("permission-fixture"))
    .putLong("server_device_id",5112).putString("config_generation","permissions-0512-fixture").putBoolean("enabled",false).commit())
   out.putString("stream","PASS permission fixture seeded; no Activity or network started\n");finish(Activity.RESULT_OK,out);return
  }
  check(c.checkSelfPermission(Manifest.permission.RECEIVE_SMS)!=PackageManager.PERMISSION_GRANTED){"Revoke RECEIVE_SMS for denial/cancel tests first"}
  Recovery.cancel(c);c.stopService(Intent(c,CollectorService::class.java));p.edit().clear().commit()
  val a=startActivitySync(Intent(c,MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) as MainActivity
  runOnMainSync {
   fun field(name:String)=MainActivity::class.java.getDeclaredField(name).apply{isAccessible=true}
   fun requestStart()=MainActivity::class.java.getDeclaredMethod("requestStart").apply{isAccessible=true}.invoke(a)
   (field("updater").get(a) as AppUpdater).close()
   requestStart()
   check(!field("pendingStart").getBoolean(a)&&!p.getBoolean("enabled",false)){"No config must stay in setup without permission flow"}
   p.edit().putString("url","https://permission-start.invalid").putString("token",Vault.seal("permission-fixture")).commit()
   field("pendingStartConfig").set(a,MainActivity::class.java.getDeclaredMethod("startConfigIdentity").apply{isAccessible=true}.invoke(a))
   field("pendingStart").setBoolean(a,true);field("startPermissionInFlight").setBoolean(a,true)
   a.onRequestPermissionsResult(1,arrayOf(Manifest.permission.RECEIVE_SMS),intArrayOf(PackageManager.PERMISSION_DENIED))
   check(!p.getBoolean("enabled",false)&&CollectorService.instance==null){"Required denial must never start or enable service"}
   check(field("pendingStart").getBoolean(a)&&!field("startPermissionInFlight").getBoolean(a)){"Denied start waits for settings without replaying prompt"}
   check((field("permissionSettingsAction").get(a) as View).visibility==View.VISIBLE){"Required denial exposes direct settings action"}
   check((field("feedback").get(a) as TextView).text.contains("短信")){"Denial identifies missing required permission"}
   if(android.os.Build.VERSION.SDK_INT<26){
    val checkbox=field("legacyNumberConsent").get(a) as CheckBox
    checkbox.isChecked=true
    check(p.getBoolean("legacy_phone_hint_consent",false)){"Checking legacy consent immediately enables the explicit consent gate"}
    check(MainActivity::class.java.getDeclaredMethod("numberHintsAllowed").apply{isAccessible=true}.invoke(a)==(c.checkSelfPermission(Manifest.permission.READ_PHONE_STATE)==PackageManager.PERMISSION_GRANTED)){"Opt-in still requires actual phone permission"}
    val cached=org.json.JSONArray().put(org.json.JSONObject().put("subscription_id",7).put("slot",1).put("phone_hint","+8613800138000").put("phone_hint_source","android_sim"))
    field("latestLocalSubs").set(a,cached);p.edit().putString("sim_status",cached.toString()).commit()
    callActivityOnPause(a)
    checkbox.isChecked=false
    check(!p.getBoolean("legacy_phone_hint_consent",true)){"Unchecking immediately withdraws consent before pending settings resume"}
    check(cached.getJSONObject(0).isNull("phone_hint")&&org.json.JSONArray(p.getString("sim_status","[]")).getJSONObject(0).isNull("phone_hint")){"Withdrawing clears memory and persisted number-hint display caches"}
    callActivityOnResume(a)
    check(field("pendingStart").getBoolean(a)&&!p.getBoolean("enabled",false)&&!p.getBoolean("legacy_phone_hint_consent",true)){"Pending resume cannot restore withdrawn consent or bypass required SMS"}
    check(!TelemetryCollector(c).collect().getJSONObject("telemetry").getJSONObject("permissions").getBoolean("phone_numbers")){"New telemetry reads remain blocked after withdrawal"}
    check(MainActivity::class.java.getDeclaredMethod("numberHintsAllowed").apply{isAccessible=true}.invoke(a)==false){"Sync reader gate sees consent withdrawal without another start click"}
   }
   val saved=Bundle();callActivityOnSaveInstanceState(a,saved)
   check(saved.getBoolean("pendingStart")&&!saved.getBoolean("startPermissionInFlight")){"Pending settings recovery survives recreation"}
   field("startPermissionInFlight").setBoolean(a,true)
   a.onRequestPermissionsResult(1,emptyArray(),intArrayOf())
   check(!field("pendingStart").getBoolean(a)&&!field("startPermissionInFlight").getBoolean(a)&&!p.getBoolean("enabled",false)){"Empty callback cancels without enabling or staying pending"}
   check(!p.getBoolean("legacy_phone_hint_consent",false)){"Required phone permission never silently opts in legacy number reading"}
   val identity=MainActivity::class.java.getDeclaredMethod("startConfigIdentity").apply{isAccessible=true}
   field("pendingStart").setBoolean(a,true);field("startPermissionInFlight").setBoolean(a,true);field("pendingStartConfig").set(a,identity.invoke(a))
   p.edit().putString("config_generation","replacement-config").commit()
   a.onRequestPermissionsResult(1,arrayOf(Manifest.permission.RECEIVE_SMS),intArrayOf(PackageManager.PERMISSION_DENIED))
   check(!field("pendingStart").getBoolean(a)&&!p.getBoolean("enabled",false)){"Old permission callback cannot start replacement config"}
   field("pendingStart").setBoolean(a,true);field("startPermissionInFlight").setBoolean(a,true);field("pendingStartConfig").set(a,identity.invoke(a))
   a.onRequestPermissionsResult(1,arrayOf(Manifest.permission.RECEIVE_SMS),intArrayOf(PackageManager.PERMISSION_DENIED))
   (field("cancelStartAction").get(a) as View).performClick()
   check(!field("pendingStart").getBoolean(a)&&(field("permissionSettingsAction").get(a) as View).visibility==View.GONE){"Explicit cancel clears the pending settings retry"}
   a.finish()
  }
  p.edit().clear().commit()
  out.putString("stream","PASS no-config guard / required denial disabled / actionable settings recovery / saved pending state / empty callback canceled / legacy consent remains opt-in / stale config callback discarded / explicit cancel clears pending\n")
  finish(Activity.RESULT_OK,out)
 }catch(t:Throwable){out.putString("stream","FAIL "+t.stackTraceToString());finish(Activity.RESULT_CANCELED,out)}}
}
