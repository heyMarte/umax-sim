package com.simmanager.collector
import android.app.Activity
import android.app.Instrumentation
import android.os.Bundle
import org.json.JSONArray
import org.json.JSONObject
/** Isolated emulator: real Uploader and encrypted config, injected HTTP, no SMS or network. */
class FeedbackRegressionRunner:Instrumentation(){
 override fun onCreate(args:Bundle?){super.onCreate(args);start()}
 override fun onStart(){
  val out=Bundle();val c=targetContext;val p=c.getSharedPreferences("config",0);val saved=p.all.toMap()
  fun restore(){val e=p.edit().clear();saved.forEach{(k,v)->when(v){is String->e.putString(k,v);is Long->e.putLong(k,v);is Int->e.putInt(k,v);is Boolean->e.putBoolean(k,v);is Float->e.putFloat(k,v);is Set<*>->{@Suppress("UNCHECKED_CAST") e.putStringSet(k,v as Set<String>)}}};e.commit()}
  try {
   check(CollectorService.instance==null){"Run on isolated emulator with collector stopped"}
   p.edit().putString("url","https://feedback.invalid").putString("token",Vault.seal("Feedback QA")).putLong("server_device_id",49).putString("config_generation","native-feedback049").putBoolean("enabled",true).remove("refresh_ack").remove("refresh_ack_generation").commit()
   var heartbeat=0
   Uploader(c,{true}){path,_->if(path=="heartbeat"){heartbeat++;check(p.getString("remote_refresh_state","")=="running");check(p.getLong("remote_refresh_started_at",0)>0);throw IllegalStateException("HTTP 503")}else JSONObject().put("accepted",JSONArray())}.sync(2049)
   check(heartbeat==1);check(p.getString("remote_refresh_state","")=="failed");check(p.getLong("remote_refresh_finished_at",0)>0);check(p.getLong("refresh_ack",0)==0L)
   val failedNotice=p.getString("remote_refresh_notified","")
   Uploader(c,{true}){path,_->if(path=="heartbeat")throw IllegalStateException("HTTP 503")else JSONObject().put("accepted",JSONArray())}.sync(2049)
   check(p.getString("remote_refresh_notified","")==failedNotice){"Same command failure must not create a fresh notification signature"}
   val success=Uploader(c,{true}){path,body->if(path=="heartbeat"){if(body.has("refresh_request_id"))check(body.getLong("refresh_request_id")==2049L);JSONObject().put("bindings",JSONArray())}else JSONObject().put("accepted",JSONArray())}
   success.sync(2049)
   check(p.getLong("refresh_ack",0)==2049L);check(p.getString("refresh_ack_generation","")=="native-feedback049");check(p.getString("remote_refresh_state","")=="success")
   val remoteTime=p.getLong("remote_refresh_finished_at",0);val remoteNotice=p.getString("remote_refresh_notified","")
   success.sync();check(p.getLong("remote_refresh_finished_at",0)==remoteTime);check(p.getString("remote_refresh_notified","")==remoteNotice){"Ordinary heartbeat must not emit operation feedback"}
   // A malformed requested heartbeat is not proof of a completed operation.
   Uploader(c,{true}){_,_->JSONObject()}.sync(2050)
   check(p.getLong("refresh_ack",0)==2049L);check(p.getLong("remote_refresh_id",0)==2050L);check(p.getString("remote_refresh_state","")=="failed")
   // A response for a replaced configuration cannot be acknowledged in the new configuration.
   Uploader(c,{true}){_,_->p.edit().putString("config_generation","replacement").commit();JSONObject().put("bindings",JSONArray())}.sync(2051)
   check(p.getLong("refresh_ack",0)==2049L);check(!remoteFeedbackVisible(p.getLong("remote_refresh_id",0),p.getString("remote_refresh_generation","")?:"","replacement"))
   p.edit().putString("check_code","ABC049").putInt("check_sub",7).putString("check_generation","replacement").putLong("check_expiry",System.currentTimeMillis()+60000).remove("check_local_at").remove("check_terminal").commit()
   ReceiveCheck.record(c,8,"SIMTEST-ABC049");check(p.getLong("check_local_at",0)==0L);ReceiveCheck.record(c,7,"SIMTEST-ABC049");check(p.getLong("check_local_at",0)>0)
   runOnMainSync{
   val fakeActivity=object:Activity(){override fun getSharedPreferences(name:String,mode:Int)=c.getSharedPreferences(name,mode)}
   val receiveCheck=ReceiveCheck(fakeActivity)
   ReceiveCheck::class.java.getDeclaredField("terminal").apply{isAccessible=true}.setBoolean(receiveCheck,true)
   val tick=ReceiveCheck::class.java.getDeclaredField("tick").apply{isAccessible=true}.get(receiveCheck) as Runnable
   tick.run();tick.run();receiveCheck.close()
   val executor=ReceiveCheck::class.java.getDeclaredField("worker").apply{isAccessible=true}.get(receiveCheck) as java.util.concurrent.ExecutorService
   check(executor.isShutdown){"ReceiveCheck close leaked executor"}
   }
   restore();out.putString("stream","PASS native operation feedback: requested running/failure/retry/success timestamps, failure does not ACK, malformed heartbeat does not ACK, ordinary heartbeat silent, repeated failure notification signature stable, replacement generation rejects stale ACK, physical-subscription local marker scope, terminal tick cannot restart and close shuts executor. No real HTTP or SMS sent.\n");finish(Activity.RESULT_OK,out)
  }catch(t:Throwable){restore();out.putString("stream","FAIL "+t.stackTraceToString());finish(Activity.RESULT_CANCELED,out)}
 }
}
