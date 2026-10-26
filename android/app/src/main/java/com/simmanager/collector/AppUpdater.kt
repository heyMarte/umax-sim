package com.simmanager.collector

import android.app.*
import android.content.*
import android.content.pm.*
import android.net.Uri
import android.os.*
import android.provider.Settings
import android.widget.*
import org.json.JSONObject
import java.io.File
import java.net.URL
import java.security.MessageDigest
import java.util.concurrent.Executors
import javax.net.ssl.HttpsURLConnection

/** Pre-O uses a device-wide setting; never assume that installing updates is allowed. */
@Suppress("DEPRECATION")
internal fun canInstallUpdates(context:Context):Boolean = if(Build.VERSION.SDK_INT>=26)context.packageManager.canRequestPackageInstalls()
 else runCatching{Settings.Secure.getInt(context.contentResolver,Settings.Secure.INSTALL_NON_MARKET_APPS,0)==1}.getOrDefault(false)

/** Separate snapshot: update authentication remains usable when collection is paused. */
private class UpdateSnapshot(val activity:Activity,val alive:()->Boolean) {
 val prefs=activity.getSharedPreferences("config",0)
 val generation=prefs.getString("config_generation","legacy")
 val url=ConnectionConfig.url(prefs.getString("url","")?:"")?:error("请先保存 HTTPS 后台地址")
 val sealed=prefs.getString("token","")?:""
 val id=prefs.getLong("server_device_id",0)
 fun current()=alive()&&generation==prefs.getString("config_generation","legacy")&&url==ConnectionConfig.url(prefs.getString("url","")?:"")&&sealed==prefs.getString("token","")&&id==prefs.getLong("server_device_id",0)
 fun connection(path:String,post:Boolean=false):HttpsURLConnection {
  check(current()){"配置已变更，请重新检查更新"}
  return (URL(url+path).openConnection() as HttpsURLConnection).apply {
   instanceFollowRedirects=false;connectTimeout=10000;readTimeout=15000
   setRequestProperty("Authorization",deviceAuthorization(Vault.open(sealed)))
   if(id>0)setRequestProperty("X-Device-Id",id.toString())
   if(post){requestMethod="POST";doOutput=true;setRequestProperty("Content-Type","application/json")}
  }
 }
}
@Suppress("DEPRECATION")
private fun version(info:PackageInfo)=if(Build.VERSION.SDK_INT>=28)info.longVersionCode else info.versionCode.toLong()
@Suppress("DEPRECATION")
private fun certs(info:PackageInfo):Set<String> {
 val signatures=if(Build.VERSION.SDK_INT>=28)info.signingInfo?.apkContentsSigners else info.signatures
 return signatures?.map{MessageDigest.getInstance("SHA-256").digest(it.toByteArray()).joinToString(""){b->"%02x".format(b)}}?.toSet()?:emptySet()
}
@Suppress("DEPRECATION")
class AppUpdater(private val activity:Activity, parent:LinearLayout):AutoCloseable {
 companion object { const val FILE_INSTALL_REQUEST=4213 }
 private val worker=Executors.newSingleThreadExecutor()
 private val handler=Handler(Looper.getMainLooper())
 @Volatile private var closed=false
 @Volatile private var connection:HttpsURLConnection?=null
 private var snapshot:UpdateSnapshot?=null
 private var manifest:JSONObject?=null
 private var apk:File?=null
 private var busy=false
 @Volatile private var resumed=false
 @Volatile private var focused=false
 @Volatile private var consent=false
 @Volatile private var consentAt=0L
 private var manual=false
 private var prompt:AlertDialog?=null
 private var progress:ProgressDialog?=null
 private val updates=activity.getSharedPreferences("app_updates",0)
 private val fileStore=UpdateFileStore(updateInstallDirectory(activity))
 private var systemFileRequested=false
 private var fileLeftActivity=updates.getBoolean("file_install_pending",false)
 private var permissionRequested=false
 @Volatile private var submitted=updates.getBoolean("file_install_pending",false)
 private val status=TextView(activity)
 private val check=Button(activity).apply{text="检查更新";setOnClickListener{checkUpdate()}}
 private val install=Button(activity).apply{text="下载更新";visibility=android.view.View.GONE;setOnClickListener{systemFileRequested=false;manual=true;confirmInstall()}}
 private val systemInstall=Button(activity).apply{text="使用系统安装方式";visibility=android.view.View.GONE;setOnClickListener{systemFileRequested=true;manual=true;confirmInstall()}}
 private val downloadPage=Button(activity).apply{text="打开下载页面";setOnClickListener{openDownloadPage()}}
 // Android 9 archive parsing only fills signingInfo when the legacy collection flag
 // is included too. Verification still compares the complete current signer sets.
 private val flags=if(Build.VERSION.SDK_INT>=28)PackageManager.GET_SIGNING_CERTIFICATES or (if(Build.VERSION.SDK_INT==28)PackageManager.GET_SIGNATURES else 0) else PackageManager.GET_SIGNATURES
 private val installed=activity.packageManager.getPackageInfo(activity.packageName,flags)
 private var configPending=false
 private val configRefresh=Runnable{configPending=false;activate()}
 private val listener=android.content.SharedPreferences.OnSharedPreferenceChangeListener{_,key->if(key=="update_install_result"){submitted=updates.getBoolean("file_install_pending",false);postpone();refreshActions();status.text=activity.getSharedPreferences("config",0).getString(key,"")}else if(key in setOf("url","token","server_device_id","config_generation")){configPending=true;handler.removeCallbacks(configRefresh);connection?.disconnect();reset();status.text="配置已变更，稍后自动检查更新";handler.postDelayed(configRefresh,650)}}
 init {
  parent.addView(TextView(activity).apply{text="应用更新 · 当前 ${installed.versionName} (${version(installed)})";textSize=16f})
  parent.addView(status);parent.addView(check);parent.addView(install);parent.addView(systemInstall);parent.addView(downloadPage)
  fileStore.cleanup(updates.getString("file_install_name",null));refreshActions()
  status.text="打开应用时自动检查新版本，也可手动检查";activity.getSharedPreferences("config",0).registerOnSharedPreferenceChangeListener(listener)
 }
 private fun reset(){systemFileRequested=false;systemInstall.visibility=android.view.View.GONE;UpdateInstallGate.authorized=false;UpdateInstallGate.pending=null;consent=false;permissionRequested=false;prompt?.dismiss();prompt=null;progress?.dismiss();progress=null;snapshot=null;manifest=null;apk?.delete();apk=null;busy=false;check.isEnabled=true;install.visibility=android.view.View.GONE;install.text="重试更新";refreshActions()}
 private fun refreshActions(){
  check.isEnabled=!busy&&!submitted;install.isEnabled=!busy&&!submitted;systemInstall.isEnabled=!busy&&!submitted;downloadPage.isEnabled=!busy&&!submitted
  systemInstall.visibility=if(apk?.isFile==true)android.view.View.VISIBLE else android.view.View.GONE
 }
 private fun openDownloadPage(){
  if(!foreground()||submitted||busy)return
  val address=UpdateHandoffPolicy.downloadPage(activity.getSharedPreferences("config",0).getString("url","")?:"")
  if(address==null){status.text="请先保存有效的 HTTPS 后台地址";return}
  runCatching{activity.startActivity(Intent(Intent.ACTION_VIEW,Uri.parse(address)))}.onFailure{status.text="无法打开下载页面，请在浏览器访问后台下载页面"}
 }
 private fun runTask(label:String,silent:Boolean=false,done:()->Unit={}, task:(UpdateSnapshot)->String){
  if(busy||closed)return
  val s=snapshot?:runCatching{UpdateSnapshot(activity){!closed}}.getOrElse{status.text=it.message;return}.also{snapshot=it}
  busy=true;refreshActions();status.text=label
  worker.execute{
   val result=runCatching{task(s)}
   handler.post{if(!closed&&snapshot===s&&s.current()){busy=false;check.isEnabled=true;install.isEnabled=!submitted;status.text=if(silent&&manifest==null){if(result.isSuccess)"已自动检查，当前无可用更新"else "可点击检查更新重试"}else result.getOrElse{"更新失败：${it.message}"};install.visibility=if(manifest!=null)android.view.View.VISIBLE else android.view.View.GONE;if(result.isSuccess)done()else{postpone();progress?.dismiss();progress=null};refreshActions()}}
  }
 }
 private fun checkUpdate(automatic:Boolean=false){if(busy||submitted)return;reset();manual=!automatic;runTask("正在检查更新…",silent=automatic,done={maybeContinue()}){s->
  val c=s.connection("/api/device/app-update",true);connection=c
  val m=try{c.outputStream.use{it.write("{}".toByteArray())};check(c.responseCode==200){"HTTP ${c.responseCode}，请检查地址与设备密钥"};val bytes=c.inputStream.use{input->val output=java.io.ByteArrayOutputStream();val buffer=ByteArray(4096);while(output.size()<=65536){val n=input.read(buffer);if(n<0)break;output.write(buffer,0,n)};output.toByteArray()};check(bytes.size<=65536){"更新清单过大"};JSONObject(String(bytes,Charsets.UTF_8))}finally{c.disconnect();connection=null}
  check(s.current()){"配置已变更"}
  if(!m.getBoolean("available")||!UpdatePolicy.newer(m.getLong("version_code"),version(installed)))"当前已是后台提供的最新版本" else {
   check(UpdatePolicy.valid(m.getLong("version_code"),version(installed),m.getLong("size"),m.getString("sha256"),m.getString("download_path"))){"更新清单无效或版本未递增"}
   handler.post{if(!closed&&snapshot===s&&s.current())manifest=m};"新版本 ${m.getString("version_name")} (${m.getLong("version_code")})\n${m.optString("notes")}"
  }
 }}
 private fun download(){val m=manifest?:return;progress=ProgressDialog(activity).apply{setTitle("正在更新");setMessage("正在下载并校验安装包…");setCancelable(false);setButton(ProgressDialog.BUTTON_NEGATIVE,"取消"){_,_->connection?.disconnect();postpone();reset();status.text="已取消更新，可稍后重试"};show()};runTask("正在下载更新…",done={progress?.dismiss();progress=null;maybeContinue()}){s->
  val file=File.createTempFile("app-update-",".apk",activity.cacheDir)
  try {
   val c=s.connection(UpdatePolicy.PATH);connection=c
   val digest=MessageDigest.getInstance("SHA-256");var total=0L
   try{check(c.responseCode==200){"下载 HTTP ${c.responseCode}"};check(c.contentLength.toLong()<0||c.contentLength.toLong()==m.getLong("size")){"下载长度不符"}
    c.inputStream.use{input->file.outputStream().use{out->val b=ByteArray(32768);var lastProgress=-1;while(true){check(s.current()&&!Thread.currentThread().isInterrupted){"下载已取消"};val n=input.read(b);if(n<0)break;total+=n;check(total<=m.getLong("size")&&total<=UpdatePolicy.MAX_SIZE){"下载超过大小限制"};digest.update(b,0,n);out.write(b,0,n);val progress=(total*100/m.getLong("size")).toInt();if(progress!=lastProgress){lastProgress=progress;handler.post{if(!closed&&snapshot===s&&s.current()){status.text="正在下载：$progress%";this@AppUpdater.progress?.setMessage("正在下载：$progress%") }}}}}}
   }finally{c.disconnect();connection=null}
   val hash=digest.digest().joinToString(""){"%02x".format(it)}
   check(UpdatePolicy.content(total,m.getLong("size"),hash,m.getString("sha256"))){"APK 大小或 SHA-256 校验失败"}
   validate(file,m);check(s.current()){"配置已变更"}
   handler.post{if(!closed&&snapshot===s&&s.current()){apk=file;install.text="重试更新"}else file.delete()}
   "下载校验完成：${m.getString("version_name")}\n${m.optString("notes")}"
  }catch(e:Exception){file.delete();throw e}
 }}
 private fun validate(file:File,m:JSONObject){
  check(Looper.myLooper()!=Looper.getMainLooper()){"更新校验必须在后台线程执行"}
  val archive=activity.packageManager.getPackageArchiveInfo(file.absolutePath,flags)?:error("无法解析 APK")
  check(archive.packageName==activity.packageName){"APK 包名不匹配"}
  check(version(archive)==m.getLong("version_code")&&version(archive)>version(installed)){"APK 版本不匹配"}
  check(certs(installed).isNotEmpty()&&certs(archive)==certs(installed)){"APK 签名与已安装应用不一致"}
  check(fileStore.verified(file,m.getLong("size"),m.getString("sha256"))){"APK 大小或 SHA-256 校验失败"}
 }
 private fun configKey():String {
  val p=activity.getSharedPreferences("config",0)
  val value=listOf(p.getString("url",""),p.getString("token",""),p.getLong("server_device_id",0).toString(),p.getString("config_generation","legacy")).joinToString("\u0000")
  return MessageDigest.getInstance("SHA-256").digest(value.toByteArray()).joinToString(""){"%02x".format(it)}
 }
 private fun foreground()=resumed&&focused&&!closed&&!activity.isFinishing
 private fun accepted()=consent&&System.currentTimeMillis()-consentAt in 0..900000
 private fun confirmInstall(){
  if(submitted||busy||!foreground())return
  if(snapshot?.current()!=true){reset();status.text="配置已变更，请重新检查更新";return}
  val m=manifest?:return
  if(prompt!=null||!AutoUpdatePolicy.prompt(manual,updates.getLong("postpone_${configKey()}_${m.getLong("version_code")}",0),System.currentTimeMillis()))return
  val counts=Queue(activity).use{it.count() to it.rejectedCount()}
  prompt=AlertDialog.Builder(activity).setTitle("发现新版本 ${m.optString("version_name")}")
   .setMessage("${m.optString("notes")}\n\n当前版本 ${installed.versionName}\n待上传 ${counts.first} 条，异常隔离 ${counts.second} 条。建议先同步。更新会保留队列，安装可能短暂停止采集。\n同意后自动下载、校验并打开 Android 安装界面。")
   .setNegativeButton("稍后"){_,_->postpone()}.setPositiveButton("立即更新"){_,_->consent=true;consentAt=System.currentTimeMillis();handler.post{maybeContinue()}}
   .setOnCancelListener{postpone()}.create().also{dialog->dialog.setOnDismissListener{prompt=null};dialog.show()}
 }
 private fun postpone(){val m=manifest?:return;updates.edit().putLong("postpone_${configKey()}_${m.getLong("version_code")}",System.currentTimeMillis()+86400000).apply();consent=false;manual=false}
 private fun maybeContinue(){
  if(consent&&!accepted()){postpone();status.text="更新授权已过期，请点击重试更新"}
  if(!foreground()||busy||submitted)return
  if(accepted()&&apk==null&&snapshot?.current()==true){download();return}
  if(accepted()&&apk!=null&&snapshot?.current()==true){
   if(canInstallUpdates(activity)){permissionRequested=false;if(UpdateHandoffPolicy.systemFile(Build.VERSION.SDK_INT,systemFileRequested))installFile()else installApk()}
   else if(!permissionRequested){permissionRequested=true;status.text="请允许安装更新，返回后将自动继续";runCatching{activity.startActivity(if(Build.VERSION.SDK_INT>=26)Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,Uri.parse("package:${activity.packageName}"))else Intent(Settings.ACTION_SECURITY_SETTINGS))}.onFailure{permissionRequested=false;postpone();status.text="无法打开安装权限设置，请允许后点击重试更新"}}
  }else if(manifest!=null&&!consent)confirmInstall()
 }
 private fun installFile(){
  if(submitted||busy)return
  if(!AutoUpdatePolicy.install(accepted(),foreground(),snapshot?.current()==true,apk!=null,canInstallUpdates(activity)))return
  val source=apk?:return;val m=manifest?:return;var staged:File?=null
  runTask("正在准备系统安装…",done={
   val file=staged
   if(file==null||!file.isFile||!accepted()||!foreground()||snapshot?.current()!=true||submitted){consent=false;status.text="请返回应用，重新确认更新"}
   else runCatching{
    check(canInstallUpdates(activity)){"安装权限未允许"}
    val intent=systemUpdateIntent(activity,file)
    check(updates.edit().putBoolean("file_install_pending",true).putString("file_install_name",file.name).putLong("file_install_target",m.getLong("version_code")).commit()){"无法保存安装状态"}
    submitted=true;consent=false;fileLeftActivity=false
    try{activity.startActivityForResult(intent,FILE_INSTALL_REQUEST)}catch(e:Exception){clearFilePending();throw e}
    status.text="已打开系统安装器，请确认安装；返回后可查看结果或重试"
   }.onFailure{postpone();status.text="无法打开系统安装器：${it.message}。可重试或打开下载页面"}
  }){captured->
   validate(source,m);check(captured.current()){"配置已变更"}
   val verified=fileStore.stage(source,m.getLong("size"),m.getString("sha256"))
   validate(verified,m);check(captured.current()){"配置已变更"};staged=verified
   "安装包已校验，正在打开系统安装器…"
  }
 }
 private fun clearFilePending(){
  updates.edit().remove("file_install_pending").remove("file_install_name").remove("file_install_target").commit()
  submitted=false;consent=false;fileLeftActivity=false;refreshActions()
 }
 /** Activity results and returning OEM installers are hints; only an installed version proves success. */
 fun installResult(requestCode:Int):Boolean {
  if(requestCode!=FILE_INSTALL_REQUEST)return false
  if(updates.getBoolean("file_install_pending",false)){
   val target=updates.getLong("file_install_target",Long.MAX_VALUE)
   val actual=runCatching{version(activity.packageManager.getPackageInfo(activity.packageName,0))}.getOrDefault(0)
   clearFilePending();postpone();manual=false
   status.text=if(actual>=target)"已核对安装版本，更新完成"else "安装未完成或已取消，可重新确认更新、使用系统安装方式或打开下载页面"
  }
  return true
 }
 private fun installApk(){if(!AutoUpdatePolicy.install(accepted(),foreground(),snapshot?.current()==true,apk!=null,canInstallUpdates(activity)))return;val file=apk?:return;val m=manifest?:return;runTask("正在提交系统安装器…"){s->
  check(canInstallUpdates(activity)){"安装权限未允许"};validate(file,m);check(s.current()){"配置已变更"}
  val pi=activity.packageManager.packageInstaller
  val params=PackageInstaller.SessionParams(PackageInstaller.SessionParams.MODE_FULL_INSTALL).apply{setAppPackageName(activity.packageName);setSize(file.length());if(Build.VERSION.SDK_INT>=31)setRequireUserAction(PackageInstaller.SessionParams.USER_ACTION_REQUIRED)}
  val id=pi.createSession(params)
  try{pi.openSession(id).use{session->session.openWrite("base.apk",0,file.length()).use{out->file.inputStream().use{it.copyTo(out)};session.fsync(out)};check(s.current()&&accepted()&&foreground()){"应用已进入后台，请返回后重试更新"}
   val callback=Intent(activity,UpdateInstallReceiver::class.java).setAction(activity.packageName+".UPDATE_RESULT")
   val pending=PendingIntent.getBroadcast(activity,id,callback,PendingIntent.FLAG_UPDATE_CURRENT or (if(Build.VERSION.SDK_INT>=31)PendingIntent.FLAG_MUTABLE else 0))
   updates.edit().putLong("session_install_target",m.getLong("version_code")).commit();submitted=true;UpdateInstallGate.authorized=true
   try{session.commit(pending.intentSender)}catch(e:Exception){submitted=false;UpdateInstallGate.authorized=false;throw e}
  }}catch(e:Exception){pi.abandonSession(id);throw e}
  "已提交 Android 安装器，请确认安装；若取消可重新点击安装更新"
 }}
 fun resume(){if(fileLeftActivity&&updates.getBoolean("file_install_pending",false))installResult(FILE_INSTALL_REQUEST);resumed=true;UpdateInstallGate.resumed=true;if(permissionRequested){permissionRequested=false;if(!canInstallUpdates(activity)){postpone();status.text="安装权限尚未允许，请允许后点击重试更新";manual=false}};handler.post{focused=activity.hasWindowFocus();activate()}}
 fun pause(){if(updates.getBoolean("file_install_pending",false))fileLeftActivity=true;resumed=false;focused=false;UpdateInstallGate.resumed=false;if(prompt!=null)postpone();prompt?.dismiss();prompt=null}
 fun focusChanged(value:Boolean){focused=value;if(value)activate()}
 private fun activate(){
  if(!foreground()||configPending)return
  if(UpdateInstallGate.pending!=null&&accepted()&&submitted){val intent=UpdateInstallGate.pending;UpdateInstallGate.pending=null;runCatching{activity.startActivity(intent)}.onFailure{status.text="无法打开系统安装器，请重试更新";submitted=false;consent=false};return}
  if(manifest!=null){maybeContinue();return}
  val p=activity.getSharedPreferences("config",0)
  val configured=ConnectionConfig.url(p.getString("url","")?:"")!=null&&!p.getString("token","").isNullOrEmpty()
  val now=System.currentTimeMillis();val key="checked_${configKey()}"
  if(!busy&&!submitted&&AutoUpdatePolicy.due(configured,foreground(),updates.getLong(key,0),now)){updates.edit().putLong(key,now).apply();checkUpdate(true)}
 }
 override fun close(){closed=true;UpdateInstallGate.authorized=false;handler.removeCallbacks(configRefresh);UpdateInstallGate.resumed=false;UpdateInstallGate.pending=null;prompt?.dismiss();progress?.dismiss();connection?.disconnect();worker.shutdownNow();activity.getSharedPreferences("config",0).unregisterOnSharedPreferenceChangeListener(listener);apk?.delete()}
}
object UpdateInstallGate {@Volatile var authorized=false;var resumed=false;var pending:Intent?=null}
class UpdateInstallReceiver:BroadcastReceiver(){
 override fun onReceive(context:Context,intent:Intent){
  val status=intent.getIntExtra(PackageInstaller.EXTRA_STATUS,PackageInstaller.STATUS_FAILURE)
  if(status==PackageInstaller.STATUS_PENDING_USER_ACTION){
   @Suppress("DEPRECATION") val confirmation=intent.getParcelableExtra<Intent>(Intent.EXTRA_INTENT)
   val result=if(confirmation!=null)runCatching{check(UpdateInstallGate.authorized){"更新授权已失效"};val next=confirmation.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);if(UpdateInstallGate.resumed)context.startActivity(next)else UpdateInstallGate.pending=next}else Result.failure(IllegalStateException("缺少安装确认界面"))
   result.onFailure{val message="无法打开安装确认界面，请返回应用重试";context.getSharedPreferences("config",0).edit().putString("update_install_result",message).apply();Toast.makeText(context,message,Toast.LENGTH_LONG).show()}
  }else {UpdateInstallGate.authorized=false;UpdateInstallGate.pending=null;val target=context.getSharedPreferences("app_updates",0).getLong("session_install_target",Long.MAX_VALUE);val actual=runCatching{version(context.packageManager.getPackageInfo(context.packageName,0))}.getOrDefault(0);val message=if(status==PackageInstaller.STATUS_SUCCESS&&actual>=target)"已核对安装版本，更新完成"else if(status==PackageInstaller.STATUS_SUCCESS)"系统已返回，请重新打开应用核对版本"else "安装未完成（$status），可返回应用重试";context.getSharedPreferences("config",0).edit().putString("update_install_result",message).apply();Toast.makeText(context,message,Toast.LENGTH_LONG).show()}
 }
}
