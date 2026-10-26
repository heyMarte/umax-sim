package com.simmanager.collector
import android.app.*
import android.content.*
import android.os.Bundle
import java.io.File
import java.security.MessageDigest
import org.json.JSONObject

/** Deterministic native UI checks: inject manifest, never call the HTTP check endpoint. */
class AutoUpdateRegressionRunner:Instrumentation(){
 override fun onCreate(args:Bundle?){super.onCreate(args);start()}
 override fun onStart(){
  val result=Bundle();var activity:MainActivity?=null
  val p=targetContext.getSharedPreferences("config",0);val saved=p.all.toMap()
  val updates=targetContext.getSharedPreferences("app_updates",0);val savedUpdates=updates.all.toMap()
  fun restore(p:android.content.SharedPreferences,values:Map<String,*>){val e=p.edit().clear();values.forEach{(k,v)->when(v){is String->e.putString(k,v);is Long->e.putLong(k,v);is Int->e.putInt(k,v);is Boolean->e.putBoolean(k,v);is Float->e.putFloat(k,v);is Set<*>->{@Suppress("UNCHECKED_CAST") e.putStringSet(k,v as Set<String>)}}};e.commit()}
  try {
   val token=Vault.seal("Native auto update QA");val generation="native-auto046"
   p.edit().putString("url","https://auto-update.invalid").putString("token",token).putLong("server_device_id",46).putString("config_generation",generation).putBoolean("enabled",false).commit()
   val hash=MessageDigest.getInstance("SHA-256").digest(listOf("https://auto-update.invalid",token,"46",generation).joinToString("\u0000").toByteArray()).joinToString(""){"%02x".format(it)}
   updates.edit().putLong("checked_$hash",System.currentTimeMillis()).commit()
   val a=startActivitySync(Intent(targetContext,MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) as MainActivity;activity=a
   val deadline=System.currentTimeMillis()+5000;while(!a.hasWindowFocus()&&System.currentTimeMillis()<deadline)Thread.sleep(50)
   check(a.hasWindowFocus()){"Activity never gained window focus"}
   val updater=MainActivity::class.java.getDeclaredField("updater").apply{isAccessible=true}.get(a) as AppUpdater
   fun field(n:String)=AppUpdater::class.java.getDeclaredField(n).apply{isAccessible=true}
   fun call(n:String)=AppUpdater::class.java.getDeclaredMethod(n).apply{isAccessible=true}.invoke(updater)
   val snapshotClass=Class.forName("com.simmanager.collector.UpdateSnapshot")
   var alive=true
   val snapshot=snapshotClass.declaredConstructors.first{it.parameterTypes.size==2}.apply{isAccessible=true}.newInstance(a,{alive&&android.os.Looper.myLooper()==android.os.Looper.getMainLooper()})
   val manifest=JSONObject().put("version_code",11).put("version_name","0.4.7").put("notes","Native automatic-update regression").put("size",123).put("sha256","a".repeat(64)).put("download_path",UpdatePolicy.PATH)
   runOnMainSync{
    check(field("snapshot").get(updater)==null){"Throttled foreground unexpectedly began network check"}
    field("snapshot").set(updater,snapshot);field("manifest").set(updater,manifest)
    updater.pause();call("maybeContinue");check(field("prompt").get(updater)==null){"Background must not show update prompt"}
    updater.resume();updater.focusChanged(true);call("maybeContinue")
    val prompt=field("prompt").get(updater) as? AlertDialog?:error("Foreground discovery did not show prompt")
    check(prompt.isShowing);check(prompt.findViewById<android.widget.TextView>(android.R.id.message).text.contains("待上传"))
    prompt.getButton(AlertDialog.BUTTON_NEGATIVE).performClick()
   }
   waitForIdleSync()
   runOnMainSync{updater.focusChanged(true);call("maybeContinue");check(field("prompt").get(updater)==null){"Postponed version prompted again"}}
   runOnMainSync{
    field("manual").set(updater,true);call("confirmInstall")
    val prompt=field("prompt").get(updater) as? AlertDialog?:error("Manual retry must bypass postponement")
    // Snapshot permits main-thread UI but rejects worker connection preflight, before any socket.
    prompt.getButton(AlertDialog.BUTTON_POSITIVE).performClick()
   }
   waitForIdleSync()
   val downloadDeadline=System.currentTimeMillis()+5000;var failed=false
   while(!failed&&System.currentTimeMillis()<downloadDeadline){runOnMainSync{failed=(field("status").get(updater) as android.widget.TextView).text.contains("更新失败")};if(!failed)Thread.sleep(50)}
   runOnMainSync{check((field("status").get(updater) as android.widget.TextView).text.contains("更新失败")){"Automatic download did not reach guarded connection preflight"};check(field("progress").get(updater)==null){"Failed download left progress dialog open"};check(!field("consent").getBoolean(updater));alive=false;call("reset");updater.pause();call("maybeContinue");check(field("progress").get(updater)==null)}
   // Denied permission clears consent without presenting a new confirmation or launching installer.
   if(!canInstallUpdates(a))runOnMainSync{field("manifest").set(updater,manifest);field("permissionRequested").setBoolean(updater,true);field("consent").setBoolean(updater,true);field("consentAt").setLong(updater,System.currentTimeMillis());updater.resume();check(!field("consent").getBoolean(updater));check(!field("permissionRequested").getBoolean(updater))}
   runOnMainSync{a.finish()};waitForIdleSync()
   restore(p,saved);restore(updates,savedUpdates)
   result.putString("stream","PASS auto updater native: configured throttle prevents network, background guard, automatic foreground discovery prompt with queue summary, persisted postponement, manual bypass, accept records consent and starts cancellable download UI without socket, stale reset, permission-denial guard where permission denied.\n")
   finish(Activity.RESULT_OK,result)
  }catch(t:Throwable){activity?.let{runOnMainSync{it.finish()}};restore(p,saved);restore(updates,savedUpdates);result.putString("stream","FAIL "+t.stackTraceToString());finish(Activity.RESULT_CANCELED,result)}
 }
}
