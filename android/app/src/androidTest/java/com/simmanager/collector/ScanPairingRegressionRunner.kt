package com.simmanager.collector

import android.app.Activity
import android.app.AlertDialog
import android.app.Instrumentation
import android.content.Intent
import android.os.Bundle
import android.widget.EditText
import android.content.pm.PackageManager
import org.json.JSONObject

/** Dedicated emulator only. No host webcam or physical device is used. */
class ScanPairingRegressionRunner:Instrumentation(){
 override fun onCreate(args:Bundle?){super.onCreate(args);start()}
 override fun onStart(){val out=Bundle();try{
  val c=targetContext;val p=c.getSharedPreferences("config",0)
  p.edit().clear().putBoolean("enabled",false).putString("url","https://old.invalid").putString("token",Vault.seal("Saved 中文")).putLong("server_device_id",42).putString("config_generation","scan-preserve").commit()
  Recovery.cancel(c);c.stopService(Intent(c,CollectorService::class.java))
  Queue(c).use{it.clear();it.add("scan-keep",JSONObject().put("event_id","scan-keep").put("body","preserve this queue"))}
  val a=startActivitySync(Intent(c,MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) as MainActivity
  val snapshot=p.all.toMap()
  fun field(name:String)=MainActivity::class.java.getDeclaredField(name).apply{isAccessible=true}.get(a)
  fun preview(value:String)=MainActivity::class.java.getDeclaredMethod("previewPairing",String::class.java).apply{isAccessible=true}.invoke(a,value)
  fun unchanged(){check(p.all==snapshot);Queue(c).use{check(it.count()==1L)};check((field("addressInput") as EditText).text.toString()=="https://old.invalid");check((field("tokenInput") as EditText).text.isEmpty());check(field("importedPairing")==null)}
  runOnMainSync{
   unchanged()
   a.onRequestPermissionsResult(552,arrayOf(android.Manifest.permission.CAMERA),intArrayOf(PackageManager.PERMISSION_DENIED));unchanged()
   MainActivity::class.java.getDeclaredMethod("onActivityResult",Int::class.javaPrimitiveType,Int::class.javaPrimitiveType,Intent::class.java).apply{isAccessible=true}.invoke(a,551,Activity.RESULT_CANCELED,null);unchanged()
   preview("https://sim.example.com/a");check(field("pairingPreview")==null);unchanged()
   preview("SIMMANAGER2\nhttps://new.invalid\n%%%\n42");unchanged()
   preview("SIMMANAGER2\nhttps://new.invalid\n"+java.util.Base64.getEncoder().encodeToString("中文 key".toByteArray())+"\n43")
   val dialog=field("pairingPreview") as AlertDialog;check(dialog.isShowing);unchanged()
   dialog.getButton(AlertDialog.BUTTON_NEGATIVE).performClick();unchanged()
  }
  // Exercise real embedded capture activity initialization and back/camera teardown.
  uiAutomation.executeShellCommand("pm grant ${c.packageName} android.permission.CAMERA").close()
  val monitor=addMonitor(PairingCaptureActivity::class.java.name,null,false)
  runOnMainSync{MainActivity::class.java.getDeclaredMethod("launchPairingScan").apply{isAccessible=true}.invoke(a)}
  val capture=waitForMonitorWithTimeout(monitor,10000)?:error("Capture did not open")
  Thread.sleep(1200)
  runOnMainSync{check(capture.requestedOrientation==android.content.pm.ActivityInfo.SCREEN_ORIENTATION_PORTRAIT);capture.onBackPressed()}
  waitForIdleSync();Thread.sleep(300)
  runOnMainSync{unchanged()};removeMonitor(monitor)
  // A successful scanner Intent must reach the same preview/identity path, while
  // the existing queue-change confirmation still prevents any silent data loss.
  runOnMainSync{
   val qr="SIMMANAGER2\nhttps://new.invalid\n"+java.util.Base64.getEncoder().encodeToString("000123".toByteArray())+"\n43"
   val result=Intent().putExtra("SCAN_RESULT",qr).putExtra("SCAN_RESULT_FORMAT","QR_CODE")
   MainActivity::class.java.getDeclaredMethod("onActivityResult",Int::class.javaPrimitiveType,Int::class.javaPrimitiveType,Intent::class.java).apply{isAccessible=true}.invoke(a,551,Activity.RESULT_OK,result)
   val preview=field("pairingPreview") as AlertDialog;check(preview.isShowing)
   preview.getButton(AlertDialog.BUTTON_POSITIVE).performClick()
  }
  // AlertDialog dispatches its click listener through the main Handler.
  waitForIdleSync()
  check((field("importedPairing") as? PairingConfig)?.deviceId==43L)
  check((field("addressInput") as EditText).text.toString()=="https://new.invalid")
  check((field("tokenInput") as EditText).text.toString()=="000123")
  check(p.all==snapshot);Queue(c).use{check(it.count()==1L)}
  sendKeyDownUpSync(android.view.KeyEvent.KEYCODE_BACK);waitForIdleSync()
  runOnMainSync{check(p.all==snapshot);Queue(c).use{check(it.count()==1L)};a.finish()}
  Queue(c).use{it.clear()}
  out.putString("stream","PASS invalid/download QR rejected / permission denial and result cancel preserve config and queue / preview cancel preserves fields / real capture activity launch and back / successful scan Intent imports hidden identity / queue change still requires confirmation / no manual device ID\n");finish(Activity.RESULT_OK,out)
 }catch(t:Throwable){out.putString("stream","FAIL "+t.stackTraceToString());finish(Activity.RESULT_CANCELED,out)}}
}
