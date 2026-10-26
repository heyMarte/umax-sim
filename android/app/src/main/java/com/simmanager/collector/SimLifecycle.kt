package com.simmanager.collector

import android.content.Context
import org.json.JSONArray
import org.json.JSONObject

internal data class ObservedTelemetry(val payload:JSONObject,val observation:SimGenerationState)
internal data class ReceiptBinding(val token:String?,val version:String?)

/** One process lock covers sampling, persisted generations and binding/receipt stamps.
 * Network calls never hold it. Neither observation nor mapping changes modify the SMS queue.
 */
internal object SimLifecycle {
 private val policy=SimGenerationPolicy()
 private val tokenPattern=Regex("[A-Za-z0-9-]{32,64}")
 fun <T> locked(block:()->T):T=synchronized(this,block)
 private fun generation(c:Context)=c.getSharedPreferences("config",0).getString("config_generation","legacy")?:"legacy"
 private fun read(c:Context):SimGenerationState?=runCatching {
  val json=JSONObject(c.getSharedPreferences("config",0).getString("sim_generation_state","")!!)
  val records=mutableMapOf<Int,SimGenerationRecord>();val rows=json.getJSONArray("records")
  for(i in 0 until rows.length()){
   val row=rows.getJSONObject(i);val token=row.getString("token");require(tokenPattern.matches(token))
   val id=row.getInt("subscription_id");require(id>=0&&!records.containsKey(id))
   records[id]=SimGenerationRecord(token,row.getInt("slot"),row.getBoolean("ready"),row.optString("known_phone_hint").takeIf{!row.isNull("known_phone_hint")&&it.isNotBlank()})
  }
  SimGenerationState(json.getString("configuration"),json.getString("status"),json.getLong("revision"),records)
 }.getOrNull()
 private fun encode(state:SimGenerationState):String {
  val records=JSONArray();state.records.forEach{(id,row)->records.put(JSONObject().put("subscription_id",id).put("token",row.token).put("slot",row.slot).put("ready",row.ready).put("known_phone_hint",row.knownPhoneHint?:JSONObject.NULL))}
  return JSONObject().put("configuration",state.configuration).put("status",state.status).put("revision",state.revision).put("records",records).toString()
 }
 fun observe(c:Context,raw:JSONObject,expectedGeneration:String=generation(c)):SimGenerationState=locked {
  val p=c.getSharedPreferences("config",0)
  if(expectedGeneration!=generation(c))return@locked SimGenerationState(generation(c),"unavailable",-1,emptyMap())
  val rows=raw.optJSONArray("subscriptions")?:JSONArray()
  val observations=(0 until rows.length()).mapNotNull { i->
   val row=rows.optJSONObject(i)?:return@mapNotNull null
   val state=row.optInt("sim_state",-1).takeIf{!row.isNull("sim_state")}
   SimObservation(row.optInt("subscription_id",-1),row.optInt("slot",0),subscriptionRoutingReady(android.os.Build.VERSION.SDK_INT,state,row.optBoolean("subscription_active",false),row.optString("sim_state_reason")),
    row.optString("phone_hint").takeIf{row.optString("phone_hint_source")=="android_sim"}?.let{normalizeSimPhoneHint(it)})
  }
  val state=policy.observe(read(c),expectedGeneration,raw.optString("subscriptions_status","unavailable"),observations)
  raw.put("sim_observation_version",1)
  for(i in 0 until rows.length())rows.optJSONObject(i)?.let{row->row.put("sim_token",state.readyToken(row.optInt("subscription_id",-1))?:JSONObject.NULL)}
  val cached=runCatching{JSONArray(p.getString("binding_details","[]"))}.getOrDefault(JSONArray())
  val filtered=JSONArray();val versions=JSONObject()
  if(p.getString("bindings_generation",null)==expectedGeneration)for(i in 0 until cached.length()){
   val b=cached.optJSONObject(i)?:continue;val id=b.optInt("subscription_id",-1);val token=b.optString("sim_token").takeIf{!b.isNull("sim_token")}
   if(token!=null&&state.readyToken(id)==token&&!b.isNull("version")){filtered.put(b);versions.put(id.toString(),b.getString("version"))}
  }
  check(p.edit().putString("sim_generation_state",encode(state)).putString("bindings",versions.toString()).putString("binding_details",filtered.toString()).putString("bindings_generation",expectedGeneration).commit())
  state
 }
 fun acceptBindings(c:Context,sent:SimGenerationState,bindings:JSONArray)=locked {
  val p=c.getSharedPreferences("config",0);val current=read(c)?:return@locked
  if(!p.getBoolean("enabled",false)||generation(c)!=sent.configuration||current.configuration!=sent.configuration)return@locked
  // A superseded request must not erase a mapping established by a later observation either.
  if(current.revision!=sent.revision)return@locked
  val accepted=JSONArray();val versions=JSONObject();val duplicateIds=(0 until bindings.length()).map{bindings.optJSONObject(it)?.optInt("subscription_id",-1)}.groupingBy{it}.eachCount()
  for(i in 0 until bindings.length()){
   val b=bindings.optJSONObject(i)?:continue;val id=b.optInt("subscription_id",-1)
   val token=b.optString("sim_token").takeIf{!b.isNull("sim_token")};val version=b.optString("version").takeIf{!b.isNull("version")&&it.isNotBlank()}
   if(duplicateIds[id]==1&&version!=null&&bindingTokenMatches(sent,current,id,token)){accepted.put(b);versions.put(id.toString(),version)}
  }
  check(p.edit().putString("bindings",versions.toString()).putString("binding_details",accepted.toString()).putString("bindings_generation",sent.configuration).commit())
 }
 fun receipt(c:Context,subscriptionId:Int,sample:()->JSONObject={TelemetryCollector(c).collectSubscriptions()}):ReceiptBinding=locked {
  val raw=sample()
  val p=c.getSharedPreferences("config",0);val expected=generation(c)
  val observed=observe(c,raw,expected)
  val token=observed.readyToken(subscriptionId).takeIf{p.getBoolean("enabled",false)&&expected==generation(c)}
  if(token==null)return@locked ReceiptBinding(null,null)
  val details=runCatching{JSONArray(p.getString("binding_details","[]"))}.getOrDefault(JSONArray())
  var version:String?=null
  if(p.getString("bindings_generation",null)==expected)for(i in 0 until details.length()){
   val b=details.optJSONObject(i)?:continue
   if(b.optInt("subscription_id",-1)==subscriptionId&&b.optString("sim_token")==token&&!b.isNull("version"))version=b.optString("version").takeIf{it.isNotBlank()}
  }
  ReceiptBinding(token,version)
 }
}
