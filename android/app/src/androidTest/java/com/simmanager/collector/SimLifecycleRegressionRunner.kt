package com.simmanager.collector

import android.app.Activity
import android.app.Instrumentation
import android.os.Bundle
import org.json.JSONArray
import org.json.JSONObject

/** Local-only platform persistence, receipt attribution, delayed responses and immutable queue. */
class SimLifecycleRegressionRunner:Instrumentation() {
 override fun onCreate(args:Bundle?){super.onCreate(args);start()}
 override fun onStart(){val out=Bundle();try{
  val c=targetContext;val p=c.getSharedPreferences("config",0)
  p.edit().clear().putBoolean("enabled",false).commit();Recovery.cancel(c);c.stopService(android.content.Intent(c,CollectorService::class.java))
  p.edit().putBoolean("enabled",true).putString("config_generation","sim059-a").commit()
  fun raw(phone:String?="+8613800138000",status:String="ok",ready:Boolean=true,empty:Boolean=false):JSONObject {
   val rows=JSONArray();if(!empty)rows.put(JSONObject().put("subscription_id",7).put("slot",1).put("sim_state",if(ready)5 else 2).put("phone_hint",phone?:JSONObject.NULL).put("phone_hint_source",if(phone!=null)"android_sim" else JSONObject.NULL))
   return JSONObject().put("subscriptions_status",status).put("subscriptions",rows)
  }
  fun binds(token:String?,version:String="v-one")=JSONArray().put(JSONObject().put("subscription_id",7).put("version",version).put("sim_token",token?:JSONObject.NULL).put("phone","+8613800138000").put("shop","Local fixture"))
  check(SimLifecycle.observe(c,raw().apply{getJSONArray("subscriptions").getJSONObject(0).put("sim_state",10)}).readyToken(7)==null){"Only public SIM_STATE_READY5 may stamp"}
  val first=SimLifecycle.observe(c,raw());val token=first.readyToken(7)!!
  check(token.matches(Regex("[A-Za-z0-9-]{32,64}")))
  check(SimLifecycle.observe(c,raw()).readyToken(7)==token){"Token did not persist"}
  SimLifecycle.acceptBindings(c,first,binds(token))
  val receipt=SimLifecycle.receipt(c,7){raw()};check(receipt.token==token&&receipt.version=="v-one")
  val original=JSONObject().put("event_id","sim059-immutable").put("subscription_id",7).put("sim_token",receipt.token).put("binding_version",receipt.version).put("body","local fixture").toString()
  Queue(c).use{it.clear();it.add("sim059-immutable",JSONObject(original))}
  check(SimLifecycle.receipt(c,7){raw(phone=null)}.token==token){"Missing hint rotated token"}
  val replaced=SimLifecycle.receipt(c,7){raw(phone="+8613900139000")};check(replaced.token!=token&&replaced.version==null){"Receipt reused stale binding"}
  SimLifecycle.acceptBindings(c,first,binds(token));check(JSONArray(p.getString("binding_details","[]")).length()==0){"Old response revived old SIM"}
  val newState=SimLifecycle.observe(c,raw(phone="+8613900139000"));SimLifecycle.acceptBindings(c,newState,binds(newState.readyToken(7),"v-two"))
  check(SimLifecycle.receipt(c,7){raw(phone="+8613900139000")}.version=="v-two")
  val hidden=SimLifecycle.receipt(c,7){raw(status="permission_denied",empty=true)};check(hidden.token==null&&hidden.version==null)
  check(JSONArray(p.getString("binding_details","[]")).length()==0)
  SimLifecycle.acceptBindings(c,newState,binds(newState.readyToken(7),"v-two"));check(JSONArray(p.getString("binding_details","[]")).length()==0)
  val restored=SimLifecycle.observe(c,raw(phone="+8613900139000"));check(restored.readyToken(7)==newState.readyToken(7))
  SimLifecycle.acceptBindings(c,newState,binds(newState.readyToken(7),"v-two"));check(JSONArray(p.getString("binding_details","[]")).length()==0){"Response from before permission gap revived"}
  SimLifecycle.acceptBindings(c,restored,binds(null));check(JSONArray(p.getString("binding_details","[]")).length()==0){"Legacy response became actionable"}
  SimLifecycle.observe(c,raw(empty=true));val reinsert=SimLifecycle.observe(c,raw(phone="+8613900139000"));check(reinsert.readyToken(7)!=restored.readyToken(7))
  p.edit().putString("config_generation","sim059-b").commit()
  val configReceipt=SimLifecycle.receipt(c,7){raw(phone="+8613900139000")};check(configReceipt.token!=reinsert.readyToken(7)&&configReceipt.version==null)
  SimLifecycle.acceptBindings(c,reinsert,binds(reinsert.readyToken(7)));check(JSONArray(p.getString("binding_details","[]")).length()==0)
  Queue(c).use{check(it.pending().single().toString()==original){"Lifecycle rewrote queued event"}}
  p.edit().putString("url","https://sim059.invalid").putString("token",Vault.seal("local SIM059 key")).putString("config_generation","sim059-transport").commit()
  var phone:String?=null;var sawMessage=false;var inFlightReceipt:ReceiptBinding?=null
  val unknown=SimLifecycle.observe(c,raw(phone=null));SimLifecycle.acceptBindings(c,unknown,binds(unknown.readyToken(7),"unknown-a"))
  Uploader(c,{true},sample={raw(phone)}){path,body->
   check(body.getInt("sim_observation_version")==1)
   if(path=="heartbeat"){
    val sentToken=body.getJSONArray("subscriptions").getJSONObject(0).getString("sim_token")
    phone="+8613900139000"
    inFlightReceipt=SimLifecycle.receipt(c,7){raw(phone)}
    JSONObject().put("bindings",binds(sentToken))
   }else{
    sawMessage=true;check(body.getJSONArray("events").getJSONObject(0).toString()==original){"Uploader retagged queued event"}
    JSONObject().put("accepted",JSONArray())
   }
  }.sync()
  check(inFlightReceipt?.token!=unknown.readyToken(7)&&inFlightReceipt?.version==null){"First readable B hint inherited unknown manual A mapping in flight"}
  check(sawMessage){"Local upload transport did not run: ${p.getString("result","")}"}
  check(JSONArray(p.getString("binding_details","[]")).length()==0){"Uploader accepted response before resampling SIM"}
  Queue(c).use{check(it.pending().single().toString()==original)}
  p.edit().remove("refresh_ack").remove("refresh_ack_generation").putLong("last_success_at",12345).commit()
  Uploader(c,{true},sample={raw(phone)}){path,_->
   check(path=="heartbeat"){"Rejected snapshot must retry before sending queued messages"}
   JSONObject().put("snapshot_accepted",false).put("bindings",JSONArray())
  }.sync(5901)
  check(p.getLong("refresh_ack",0)==0L){"Rejected snapshot acknowledged refresh"}
  check(p.getLong("last_success_at",0)==12345L){"Rejected snapshot recorded success"}
  Queue(c).use{check(it.pending().single().toString()==original);it.clear()}
  // A no-hint receipt must not consume a replacement's mapping while its sample waits.
  p.edit().putString("config_generation","sim059-concurrent").commit()
  val concurrentA=SimLifecycle.observe(c,raw());SimLifecycle.acceptBindings(c,concurrentA,binds(concurrentA.readyToken(7),"concurrent-a"))
  val sampled=java.util.concurrent.CountDownLatch(1);val release=java.util.concurrent.CountDownLatch(1);val changed=java.util.concurrent.CountDownLatch(1)
  val received=java.util.concurrent.atomic.AtomicReference<ReceiptBinding>();val error=java.util.concurrent.atomic.AtomicReference<Throwable>()
  val receiving=Thread{
   try{received.set(SimLifecycle.receipt(c,7){sampled.countDown();check(release.await(5,java.util.concurrent.TimeUnit.SECONDS));raw(phone=null)})}catch(t:Throwable){error.set(t)}
  }
  receiving.start();check(sampled.await(5,java.util.concurrent.TimeUnit.SECONDS))
  val replacing=Thread{try{SimLifecycle.locked{val b=SimLifecycle.observe(c,raw(phone="+8613900139000"));SimLifecycle.acceptBindings(c,b,binds(b.readyToken(7),"concurrent-b"))};changed.countDown()}catch(t:Throwable){error.set(t)}}
  replacing.start();val overtook=changed.await(300,java.util.concurrent.TimeUnit.MILLISECONDS);release.countDown();receiving.join(5000);replacing.join(5000)
  error.get()?.let{throw it};check(!receiving.isAlive&&!replacing.isAlive)
  check(!overtook){"Replacement overtook a receipt's in-progress sample"}
  check(received.get().token==concurrentA.readyToken(7)&&received.get().version=="concurrent-a"){"Old no-hint sample consumed replacement token/version"}
  if(android.os.Build.VERSION.SDK_INT in 23..25){
   // Android6/7 active-subscription routing preserves physical-state uncertainty and the full receipt guard.
   fun legacy(phone:String?=null,active:Boolean=true,status:String="ok"):JSONObject = raw(phone=phone,status=status).apply{
    getJSONArray("subscriptions").getJSONObject(0).put("sim_state",JSONObject.NULL).put("subscription_active",active).put("sim_state_reason","unsupported_android_version")
   }
   p.edit().putString("config_generation","legacy-routing").commit()
   val active=SimLifecycle.observe(c,legacy());val routeToken=active.readyToken(7)?:error("Legacy active subscription lacks routing token")
   check(SimLifecycle.observe(c,legacy()).readyToken(7)==routeToken)
   SimLifecycle.acceptBindings(c,active,binds(routeToken,"legacy-manual-binding"))
   val stamped=SimLifecycle.receipt(c,7){legacy()}
   check(stamped.token==routeToken&&stamped.version=="legacy-manual-binding"){"Legacy manual mapping was not stamped at receipt"}
   check(SimLifecycle.receipt(c,8){legacy()}.token==null){"SIM2 inherited SIM1 routing"}
   val firstHint=SimLifecycle.receipt(c,7){legacy("+8613900139000")}
   check(firstHint.token!=routeToken&&firstHint.version==null){"First legacy hint reused unknown-card mapping"}
   SimLifecycle.acceptBindings(c,active,binds(routeToken,"legacy-manual-binding"))
   check(JSONArray(p.getString("binding_details","[]")).length()==0){"Stale legacy response revived mapping"}
   check(SimLifecycle.receipt(c,7){legacy(active=false)}.token==null)
   check(SimLifecycle.receipt(c,7){legacy(status="permission_denied")}.token==null)
   val reactivated=SimLifecycle.observe(c,legacy());check(reactivated.readyToken(7)!=routeToken)
  }
  p.edit().putBoolean("enabled",false).commit()
  val live=TelemetryCollector(c).collect();check(live.getInt("sim_observation_version")==1)
  check(!p.getString("sim_generation_state","")!!.contains("iccid",true));check(!live.toString().contains("iccid",true))
  out.putString("stream","PASS SIM059 persisted generation / receipt resampling / hint disappearance / remove-reinsert / permission gap / stale response / config isolation / legacy response rejection / immutable queue / protocol snapshot\n")
  finish(Activity.RESULT_OK,out)
 }catch(t:Throwable){out.putString("stream","FAIL "+t.stackTraceToString());finish(Activity.RESULT_CANCELED,out)}}
}
