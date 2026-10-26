package com.simmanager.collector
import android.app.Instrumentation
import android.app.Activity
import android.os.Bundle
import android.app.job.JobScheduler
import org.json.JSONObject
import org.json.JSONArray
class RegressionRunner:Instrumentation(){
 override fun onCreate(args:Bundle?){super.onCreate(args);start()}
 override fun onStart(){val out=Bundle();try{
  val c=targetContext;val p=c.getSharedPreferences("config",0)
  p.edit().putBoolean("enabled",false).commit();Recovery.cancel(c);c.stopService(android.content.Intent(c,CollectorService::class.java));Thread.sleep(200)
  check(c.packageName=="com.simmanager.collector")
  p.edit().clear().putBoolean("enabled",true).putString("url","https://sim.invalid").putString("token",Vault.seal("TestOnlyNativeRegression032")).putString("config_generation","regression032").commit()
  Queue(c).use{q->q.clear();for((id,body) in listOf("bad032" to "x".repeat(10001),"good032" to "OTP 123456")){q.add(id,JSONObject().put("event_id",id).put("body",body))}}
  var seenRefresh=0L
  val accepted=mutableListOf<String>()
  val runner=Uploader(c,{true}){path,body->
   if(path=="heartbeat"){seenRefresh=body.optLong("refresh_request_id",seenRefresh);JSONObject().put("bindings",JSONArray())}else{
    val events=body.getJSONArray("events")
    for(i in 0 until events.length())if(events.getJSONObject(i).getString("body").length>10000)throw IllegalStateException("HTTP 422")
    val ids=JSONArray();for(i in 0 until events.length()){val id=events.getJSONObject(i).getString("event_id");ids.put(id);accepted.add(id)}
    JSONObject().put("accepted",ids)
   }
  }
  val deadline=System.currentTimeMillis()+30000
  while(accepted.isEmpty()&&System.currentTimeMillis()<deadline){runner.sync();if(accepted.isEmpty())Thread.sleep(100)}
  Queue(c).use{q->check(q.count()==0L){"queue=${q.count()} isolated=${q.rejectedCount()} accepted=$accepted result=${p.getString("result","")}"};check(q.rejectedCount()==1L);check(accepted==listOf("good032"));q.retryRejected();check(q.count()==1L);check(q.rejectedCount()==0L);q.clear()}
  check(p.getLong("last_sms_upload_at",0)>0)
  runner.sync(7331L);check(seenRefresh==7331L);check(p.getLong("refresh_ack",0)==7331L);check(p.getString("refresh_ack_generation","")=="regression032")
  p.edit().putString("check_code","A1B2C3").putInt("check_sub",7).putString("check_generation","regression032").putLong("check_expiry",System.currentTimeMillis()+60000).remove("check_local_at").commit()
  ReceiveCheck.record(c,8,"SIMTEST-A1B2C3");check(p.getLong("check_local_at",0)==0L)
  ReceiveCheck.record(c,7,"SIMTEST-A1B2C3");check(p.getLong("check_local_at",0)>0)

  Recovery.schedule(c);val js=c.getSystemService(JobScheduler::class.java);check(js.allPendingJobs.any{it.id==3201});check(js.allPendingJobs.any{it.id==3202});Recovery.cancel(c)
  p.edit().putBoolean("enabled",false).commit()
  p.edit().putLong("last_received_at",12345).putLong("last_sms_upload_at",12345).putLong("last_success_at",12345).putInt("consecutive_failures",99).putString("last_error","OLD_CONNECTION_ERROR").commit()
  val activity=startActivitySync(android.content.Intent(c,MainActivity::class.java).addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK)) as MainActivity
  runOnMainSync{
   val field=MainActivity::class.java.getDeclaredField("addressInput").apply{isAccessible=true}.get(activity) as android.widget.EditText
   field.setText("https://new.invalid")
   MainActivity::class.java.getDeclaredMethod("saveAndStart").apply{isAccessible=true}.invoke(activity)
  }
  check(p.getLong("refresh_ack",0)==0L);check(!p.contains("refresh_ack_generation"));
  check(p.getLong("last_received_at",0)==0L);check(p.getLong("last_sms_upload_at",0)==0L);check(p.getLong("last_success_at",0)!=12345L);check(p.getInt("consecutive_failures",0)!=99);check(p.getString("last_error","")!="OLD_CONNECTION_ERROR")
  val startedDeadline=System.currentTimeMillis()+5000
  while(p.getBoolean("enabled",false)&&CollectorService.instance==null&&System.currentTimeMillis()<startedDeadline)Thread.sleep(50)
  p.edit().putBoolean("enabled",false).commit();Recovery.cancel(c);c.stopService(android.content.Intent(c,CollectorService::class.java));runOnMainSync{activity.finish()};waitForIdleSync()

  p.edit().clear().commit()
  val fresh=startActivitySync(android.content.Intent(c,MainActivity::class.java).addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK)) as MainActivity
  runOnMainSync{
   fun input(name:String)=MainActivity::class.java.getDeclaredField(name).apply{isAccessible=true}.get(fresh) as android.widget.EditText
   check(input("tokenInput").text.isEmpty())
   check(input("addressInput").text.toString()=="https://sim.example.com")
   check(MainActivity::class.java.declaredFields.none{it.name=="deviceIdInput"})
   fresh.finish()
  }
  p.edit().putString("url","https://old.invalid").putString("token",Vault.seal("Old Key 中文")).putLong("server_device_id",42).putBoolean("enabled",false).commit()
  val preserved=startActivitySync(android.content.Intent(c,MainActivity::class.java).addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK)) as MainActivity
  runOnMainSync{
   fun input(name:String)=MainActivity::class.java.getDeclaredField(name).apply{isAccessible=true}.get(preserved) as android.widget.EditText
   check(input("tokenInput").text.isEmpty())
   check(input("addressInput").text.toString()=="https://old.invalid")
   check(p.getLong("server_device_id",0)==42L)
   check(Vault.open(p.getString("token",null)!!)=="Old Key 中文")
   p.edit().putLong("last_received_at",12345).putString("config_generation","old-id-generation").commit()
   MainActivity::class.java.getDeclaredField("importedPairing").apply{isAccessible=true}.set(preserved,PairingConfig("https://old.invalid","Old Key 中文",43))
   MainActivity::class.java.getDeclaredMethod("saveAndStart").apply{isAccessible=true}.invoke(preserved)
   check(p.getLong("server_device_id",0)==43L)
   check(p.getString("config_generation","")!="old-id-generation")
   check(p.getLong("last_received_at",0)==0L)
  }
  val switchedDeadline=System.currentTimeMillis()+5000
  while(CollectorService.instance==null&&System.currentTimeMillis()<switchedDeadline)Thread.sleep(50)
  check(CollectorService.instance!=null){"Collector did not start"}
  p.edit().putBoolean("enabled",false).commit();Recovery.cancel(c);c.stopService(android.content.Intent(c,CollectorService::class.java));runOnMainSync{preserved.finish()}

  out.putString("stream","PASS native queue migration / mixed 422 isolation / good SMS upload / retry / recovery scheduling / requested snapshot acknowledgement / scoped local SMS marker / actual configuration switch clears old statistics / fresh blank key and default URL / preserved old key and device ID / device ID switch isolation\n");finish(Activity.RESULT_OK,out)
 }catch(t:Throwable){out.putString("stream","FAIL "+t.stackTraceToString());finish(Activity.RESULT_CANCELED,out)}}
}
