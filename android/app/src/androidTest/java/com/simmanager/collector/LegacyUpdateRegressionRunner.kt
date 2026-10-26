package com.simmanager.collector

import android.app.Activity
import android.app.Instrumentation
import android.content.Intent
import android.content.SharedPreferences
import android.os.Bundle
import android.widget.Button
import android.widget.LinearLayout
import android.widget.TextView
import androidx.core.content.FileProvider
import java.io.File
import java.lang.reflect.InvocationTargetException
import java.security.MessageDigest
import org.json.JSONObject

/** Local emulator fixture only. No HTTP. launchInstaller leaves confirmation open for host UI automation. */
class LegacyUpdateRegressionRunner:Instrumentation() {
 private var launch=false
 private var fallback=false
 override fun onCreate(args:Bundle?){super.onCreate(args);launch=args?.getString("launchInstaller")=="true";fallback=args?.getString("systemFile")=="true";start()}
 override fun onStart(){
  val out=Bundle();val c=targetContext;val p=c.getSharedPreferences("config",0);val u=c.getSharedPreferences("app_updates",0)
  val saved=p.all.toMap();val savedUpdates=u.all.toMap();var a:MainActivity?=null
  fun restore(prefs:SharedPreferences,values:Map<String,*>){val e=prefs.edit().clear();for((k,v)in values)when(v){is String->e.putString(k,v);is Boolean->e.putBoolean(k,v);is Long->e.putLong(k,v);is Int->e.putInt(k,v);is Float->e.putFloat(k,v);is Set<*>->{@Suppress("UNCHECKED_CAST") e.putStringSet(k,v as Set<String>)}};e.commit()}
  try {
   val fixture=File(c.filesDir,"update-fixture.apk");check(fixture.isFile){"Stage files/update-fixture.apk first"}
   val sealed=Vault.seal("local legacy update regression")
   p.edit().clear().putString("url","https://legacy-update.invalid").putString("token",sealed).putString("config_generation","legacy-update-test").putLong("server_device_id",513).putBoolean("enabled",false).commit()
   if(launch){p.edit().putString("installer_preserve_marker","keep-during-real-update").commit();Queue(c).use{q->if(q.count()==0L)q.add("installer-preserve-event",JSONObject().put("event_id","installer-preserve-event").put("body","Local queued payload must stay immutable").put("sender","local-fixture").put("subscription_id",7).put("binding_version","preserve-binding"))}}
   val key=MessageDigest.getInstance("SHA-256").digest(listOf("https://legacy-update.invalid",sealed,"513","legacy-update-test").joinToString("\u0000").toByteArray()).joinToString(""){"%02x".format(it)}
   u.edit().clear().putLong("checked_$key",System.currentTimeMillis()).commit()
   a=startActivitySync(Intent(c,MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) as MainActivity
   val activity=a;waitForIdleSync()
   val updater=MainActivity::class.java.getDeclaredField("updater").apply{isAccessible=true}.get(activity) as AppUpdater
   fun field(name:String)=AppUpdater::class.java.getDeclaredField(name).apply{isAccessible=true}
   val m=JSONObject().put("version_code",c.packageManager.getPackageArchiveInfo(fixture.absolutePath,0)!!.versionCode.toLong()).put("version_name","local fixture").put("size",fixture.length()).put("sha256",MessageDigest.getInstance("SHA-256").digest(fixture.readBytes()).joinToString(""){"%02x".format(it)}).put("notes","Local emulator fixture").put("download_path",UpdatePolicy.PATH)
   val validate=AppUpdater::class.java.getDeclaredMethod("validate",File::class.java,JSONObject::class.java).apply{isAccessible=true}
   validate.invoke(updater,fixture,m)
   runOnMainSync{try{validate.invoke(updater,fixture,m);error("APK verification ran on UI thread")}catch(e:InvocationTargetException){check(e.cause is IllegalStateException&&e.cause?.message=="更新校验必须在后台线程执行")}}
   for(invalid in listOf(JSONObject(m.toString()).put("sha256","0".repeat(64)),JSONObject(m.toString()).put("size",fixture.length()+1))){
    try{validate.invoke(updater,fixture,invalid);error("Altered digest/length accepted before handoff")}catch(e:InvocationTargetException){check(e.cause is IllegalStateException)}
   }
   val source=File.createTempFile("legacy-source-",".apk",c.cacheDir);fixture.copyTo(source,true)
   val store=UpdateFileStore(updateInstallDirectory(c));val staged=store.stage(source,m.getLong("size"),m.getString("sha256"))
   val intent=systemUpdateIntent(c,staged);val uri=intent.data!!
   check(intent.action==Intent.ACTION_VIEW&&intent.type=="application/vnd.android.package-archive")
   check(intent.getBooleanExtra(Intent.EXTRA_RETURN_RESULT,false))
   if(android.os.Build.VERSION.SDK_INT==23){
    check(uri.scheme=="file"&&File(uri.path!!).canonicalFile==staged.canonicalFile)
    check(staged.parentFile!!.canonicalFile==File(c.cacheDir,"verified-updates").canonicalFile)
    check(c.packageManager.resolveActivity(intent,0)!=null){"Android 6 installer cannot resolve update URI"}
    check(android.system.Os.stat(staged.parentFile!!.absolutePath).st_mode and 511==457){"Legacy APK directory must be 0711"}
    check(android.system.Os.stat(staged.absolutePath).st_mode and 511==292){"Legacy APK must be 0444"}
    staged.inputStream().use{check(it.read()==0x50&&it.read()==0x4b)}
    try{systemUpdateIntent(c,source);error("Legacy install exposed generic private file")}catch(_:IllegalArgumentException){}
   }else{
    check(uri.scheme=="content"&&uri.authority==c.packageName+".updatefiles")
    check(intent.flags and Intent.FLAG_GRANT_READ_URI_PERMISSION!=0&&intent.flags and Intent.FLAG_GRANT_WRITE_URI_PERMISSION==0)
    check(intent.clipData!!.getItemAt(0).uri==uri)
    val info=c.packageManager.resolveContentProvider(uri.authority!!,0)!!;check(!info.exported&&info.grantUriPermissions)
    c.contentResolver.openInputStream(uri)!!.use{check(it.read()==0x50&&it.read()==0x4b)}
   }
   try{FileProvider.getUriForFile(c,c.packageName+".updatefiles",source);error("Provider exposes generic cache directory")}catch(_:IllegalArgumentException){}
   if(launch){
    check(canInstallUpdates(activity)){"Enable unknown source permission/appop on emulator first"}
    val cls=Class.forName("com.simmanager.collector.UpdateSnapshot");val snap=cls.declaredConstructors.first{it.parameterTypes.size==2}.apply{isAccessible=true}.newInstance(activity,{true})
    runOnMainSync{field("snapshot").set(updater,snap);field("manifest").set(updater,m);field("apk").set(updater,source);field("manual").setBoolean(updater,true);field("systemFileRequested").setBoolean(updater,fallback);AppUpdater::class.java.getDeclaredMethod("confirmInstall").apply{isAccessible=true}.invoke(updater)}
    sendStatus(0,Bundle().apply{putString("stream","READY update confirmation visible; click 立即更新 to inspect installer. Fixture ${m.getLong("version_code")}; API ${android.os.Build.VERSION.SDK_INT}; explicit file $fallback.\n")})
    val until=System.currentTimeMillis()+60000
    while(System.currentTimeMillis()<until&&!activity.isDestroyed)Thread.sleep(250)
   }else{
    staged.setLastModified(1)
    u.edit().putBoolean("file_install_pending",true).putString("file_install_name",staged.name).putLong("file_install_target",m.getLong("version_code")).commit()
    runOnMainSync{field("apk").set(updater,source);AppUpdater::class.java.getDeclaredMethod("reset").apply{isAccessible=true}.invoke(updater);updater.close()}
    check(staged.isFile&&!source.exists()){"Reset/close removed installer-owned file"}
    lateinit var recreated:AppUpdater
    runOnMainSync{recreated=AppUpdater(activity,LinearLayout(activity))}
    check(staged.isFile){"Recreation cleanup removed active installer file"}
    val retrySource=File.createTempFile("legacy-retry-",".apk",c.cacheDir).also{fixture.copyTo(it,true)}
    runOnMainSync{
     check(field("submitted").getBoolean(recreated)){"Recreation lost pending handoff lock"}
     field("manifest").set(recreated,m)
     val snapshotClass=Class.forName("com.simmanager.collector.UpdateSnapshot")
     field("snapshot").set(recreated,snapshotClass.declaredConstructors.first{it.parameterTypes.size==2}.apply{isAccessible=true}.newInstance(activity,{true}))

     check(!recreated.installResult(42));check(recreated.installResult(AppUpdater.FILE_INSTALL_REQUEST))
     check(!field("submitted").getBoolean(recreated)&&!field("consent").getBoolean(recreated))
     check((field("status").get(recreated) as TextView).text.toString().startsWith("安装未完成")){"Canceled/missing installation claimed success"}
     recreated.resume();recreated.focusChanged(true);check(field("prompt").get(recreated)==null){"Return must wait for fresh manual consent"}
     check(u.getLong("postpone_${key}_${m.getLong("version_code")}",0)>System.currentTimeMillis()){"Cancel did not postpone automatic prompt"}
     field("apk").set(recreated,retrySource);AppUpdater::class.java.getDeclaredMethod("refreshActions").apply{isAccessible=true}.invoke(recreated)
     val retry=field("systemInstall").get(recreated) as Button;check(retry.visibility==android.view.View.VISIBLE);retry.performClick()
     check(field("prompt").get(recreated)!=null){"Explicit system-install button must allow fresh retry"}
     recreated.close();activity.finish()
    }
    check(staged.isFile&&!u.getBoolean("file_install_pending",false)){"Cancel deleted installer file or retained pending lock"}
    staged.delete();waitForIdleSync();restore(p,saved);restore(u,savedUpdates)
   }
   out.putString("stream","PASS legacy updater: background-only verification; package/signature/version/digest/size validation; API23 file URI resolvable and dedicated APK scope; API24+ content URI read+ClipData; provider scope/nonexported; independent staged copy; reset/close/recreation retention; canceled result unlocks without success or automatic prompt. "+if(launch)"UI fixture left for host test.\n"else "No HTTP or installer launch.\n")
   finish(Activity.RESULT_OK,out)
  }catch(t:Throwable){a?.let{runOnMainSync{it.finish()}};restore(p,saved);restore(u,savedUpdates);out.putString("stream","FAIL "+t.stackTraceToString());finish(Activity.RESULT_CANCELED,out)}
 }
}
