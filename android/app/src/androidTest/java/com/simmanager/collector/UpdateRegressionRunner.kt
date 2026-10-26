package com.simmanager.collector

import android.app.Activity
import android.app.Instrumentation
import android.content.Intent
import android.os.Bundle
import android.widget.TextView
import java.io.File
import java.lang.reflect.InvocationTargetException
import org.json.JSONObject

/** Isolated emulator only. Fixture is streamed by adb into target-private files. No HTTP requests. */
class UpdateRegressionRunner:Instrumentation(){
 private var launchInstaller=false
 override fun onCreate(args:Bundle?){super.onCreate(args);launchInstaller=args?.getString("launchInstaller")=="true";start()}
 override fun onStart(){
  val out=Bundle();var activity:MainActivity?=null
  val p=targetContext.getSharedPreferences("config",0);val saved=p.all.toMap()
  fun restore(){val edit=p.edit().clear();for((k,v)in saved)when(v){is String->edit.putString(k,v);is Boolean->edit.putBoolean(k,v);is Long->edit.putLong(k,v);is Int->edit.putInt(k,v);is Float->edit.putFloat(k,v);is Set<*>->{@Suppress("UNCHECKED_CAST") edit.putStringSet(k,v as Set<String>)}};edit.commit()}
  try {
   val fixture=File(targetContext.filesDir,"update-fixture.apk")
   check(fixture.isFile){"Stage fixture first: adb shell run-as com.simmanager.collector sh -c 'cat > files/update-fixture.apk' < fixture.apk"}
   p.edit().putString("url","https://updater-regression.invalid").putString("token",Vault.seal("Updater regression 中文")).putLong("server_device_id",43).putString("config_generation","updater-regression").putBoolean("enabled",false).commit()
   activity=startActivitySync(Intent(targetContext,MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) as MainActivity
   val a=activity
   val updater=MainActivity::class.java.getDeclaredField("updater").apply{isAccessible=true}.get(a) as AppUpdater
   fun field(name:String)=AppUpdater::class.java.getDeclaredField(name).apply{isAccessible=true}
   val snapshotClass=Class.forName("com.simmanager.collector.UpdateSnapshot")
   fun snapshot(alive:()->Boolean={true}):Any=snapshotClass.declaredConstructors.first{it.parameterTypes.size==2}.apply{isAccessible=true}.newInstance(a,alive)
   fun current(s:Any)=snapshotClass.getDeclaredMethod("current").apply{isAccessible=true}.invoke(s) as Boolean
   check(current(snapshot())){"Stopped collector must still permit update auth"}
   check(!current(snapshot{false})){"Destroyed lifetime must reject snapshot"}
   for((key,value)in listOf("url" to "https://other.invalid","token" to Vault.seal("other"),"config_generation" to "other")){
    val old=p.getString(key,null);val s=snapshot();runOnMainSync{field("snapshot").set(updater,s);field("apk").set(updater,File.createTempFile("app-update-test-",".apk",a.cacheDir));p.edit().putString(key,value).commit()};waitForIdleSync()
    check(!current(s)){"Stale $key snapshot accepted"};check(field("snapshot").get(updater)==null){"Stale updater not reset for $key"};check(field("apk").get(updater)==null)
    p.edit().putString(key,old).commit()
   }
   val idSnapshot=snapshot();p.edit().putLong("server_device_id",44).commit();check(!current(idSnapshot));p.edit().putLong("server_device_id",43).commit()
   val fixtureVersion=targetContext.packageManager.getPackageArchiveInfo(fixture.absolutePath,0)!!.versionCode.toLong()
   check(fixtureVersion>BuildConfig.VERSION_CODE){"Stage a fixture newer than the installed app"}
   val manifest=JSONObject().put("version_code",fixtureVersion).put("version_name","0.4.7").put("size",fixture.length()).put("sha256",java.security.MessageDigest.getInstance("SHA-256").digest(fixture.readBytes()).joinToString(""){"%02x".format(it)}).put("notes","Emulator update fixture").put("download_path",UpdatePolicy.PATH)
   val validate=AppUpdater::class.java.getDeclaredMethod("validate",File::class.java,JSONObject::class.java).apply{isAccessible=true}
   validate.invoke(updater,fixture,manifest)
   fun rejected(m:JSONObject){try{validate.invoke(updater,fixture,m);error("Invalid APK manifest was accepted")}catch(e:InvocationTargetException){check(e.cause is IllegalStateException)}}
   try{validate.invoke(updater,File(context.applicationInfo.sourceDir),manifest);error("Wrong-package test APK accepted")}catch(e:InvocationTargetException){check(e.cause is IllegalStateException);check(e.cause?.message=="APK 包名不匹配")}
   rejected(JSONObject(manifest.toString()).put("version_code",fixtureVersion+1));rejected(JSONObject(manifest.toString()).put("version_code",fixtureVersion-1))
   check(!UpdatePolicy.newer(9,9)&&!UpdatePolicy.newer(8,9)&&UpdatePolicy.newer(10,9))
   check(!UpdatePolicy.content(fixture.length(),fixture.length(),"0".repeat(64),manifest.getString("sha256")))
   check(!UpdatePolicy.content(fixture.length()-1,fixture.length(),manifest.getString("sha256"),manifest.getString("sha256")))
   if(launchInstaller){
    check(canInstallUpdates(a)){"Enable unknown-source appop before launchInstaller test"}
    val cached=File(a.cacheDir,"verified-update-fixture.apk");fixture.copyTo(cached,true)
    runOnMainSync{field("snapshot").set(updater,snapshot());field("manifest").set(updater,manifest);field("apk").set(updater,cached);AppUpdater::class.java.getDeclaredMethod("confirmInstall").apply{isAccessible=true}.invoke(updater)}
    sendStatus(0,Bundle().apply{putString("stream","READY queue confirmation visible; instrumentation stays alive for up to 45 seconds.\n")})
    val deadline=System.currentTimeMillis()+45000
    while(System.currentTimeMillis()<deadline&&!a.isDestroyed){Thread.sleep(250)}
   }else {runOnMainSync{a.finish()};waitForIdleSync();restore()}
   out.putString("stream","PASS updater: stopped-service auth, destroyed lifetime, URL/key/generation/ID invalidation, stale APK reset, real signed newer fixture accepted, wrong package/code rejected, up-to-date version decision, digest/length rejection. "+if(launchInstaller)"Queue warning open; click 继续安装 to inspect Android installer. Test preferences retained until emulator reset.\n"else "No network or installation requested; preferences restored.\n")
   finish(Activity.RESULT_OK,out)
  }catch(t:Throwable){activity?.let{runOnMainSync{it.finish()}};restore();out.putString("stream","FAIL "+t.stackTraceToString());finish(Activity.RESULT_CANCELED,out)}
 }
}
