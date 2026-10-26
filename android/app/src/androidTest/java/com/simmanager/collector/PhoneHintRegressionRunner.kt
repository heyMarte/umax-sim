package com.simmanager.collector

import android.app.Activity
import android.app.Instrumentation
import android.os.Bundle
import android.view.View
import android.view.ViewGroup
import android.widget.TextView
import org.json.JSONArray
import org.json.JSONObject

/** Isolated emulator fixture: deterministic dual-SIM rendering without carrier number claims. */
class PhoneHintRegressionRunner:Instrumentation() {
 override fun onCreate(args:Bundle?){super.onCreate(args);start()}
 override fun onStart(){
  val out=Bundle()
  try {
   val c=targetContext;val p=c.getSharedPreferences("config",0)
   p.edit().clear().putBoolean("enabled",false).commit()
   Recovery.cancel(c);c.stopService(android.content.Intent(c,CollectorService::class.java))
   val sample=TelemetryCollector(c).collect()
   check(sample.getJSONObject("telemetry").getString("app_version")==c.packageManager.getPackageInfo(c.packageName,0).versionName)
   val activity=startActivitySync(android.content.Intent(c,MainActivity::class.java).addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK)) as MainActivity
   runOnMainSync {
    fun field(name:String)=MainActivity::class.java.getDeclaredField(name).apply{isAccessible=true}
    field("samplingTelemetry").setBoolean(activity,true)
    val sims=JSONArray().put(JSONObject().put("subscription_id",7).put("slot",1).put("carrier","中国移动").put("phone_hint","+8613800138000").put("phone_hint_source","android_sim"))
     .put(JSONObject().put("subscription_id",8).put("slot",2).put("carrier","Globe").put("phone_hint",JSONObject.NULL).put("phone_hint_source",JSONObject.NULL))
    field("latestLocalSubs").set(activity,sims)
    fun refresh(){MainActivity::class.java.getDeclaredMethod("updateStatus").apply{isAccessible=true}.invoke(activity)}
    fun texts(v:View):List<String> = when(v){is TextView->listOf(v.text.toString());is ViewGroup->(0 until v.childCount).flatMap{texts(v.getChildAt(it))};else->emptyList()}
    fun cards()=texts(field("simList").get(activity) as View)
    refresh()
    check(cards().contains("+8613800138000 · 待确认"))
    check(cards().contains("待识别号码"))
    check(JSONObject(p.getString("bindings","{}")?:"{}").length()==0){"Hint must not create a binding"}
    val first=(field("simList").get(activity) as ViewGroup).getChildAt(0) as ViewGroup
    check((first.getChildAt(0) as TextView).text.toString()=="+8613800138000 · 待确认")
    p.edit().putString("binding_details",JSONArray().put(JSONObject().put("subscription_id",7).put("phone","+8613900139000").put("shop","测试店铺")).toString()).putString("bindings","{\"7\":2}").commit()
    refresh();check(cards().contains("+8613900139000"));check(!cards().contains("+8613800138000 · 待确认"));check(cards().contains("测试店铺"))
    val required=MainActivity::class.java.getDeclaredMethod("required").apply{isAccessible=true}.invoke(activity) as List<*>
    check(!required.contains(android.Manifest.permission.READ_PHONE_NUMBERS))
    check(texts(activity.window.decorView).any{it.contains("无需验证短信")})
    check(texts(activity.window.decorView).any{it.contains("读取本机号码 · 可选")})
    activity.finish()
   }
   p.edit().clear().commit()
   out.putString("stream","PASS ${BuildConfig.VERSION_NAME} telemetry / dual SIM phone-first cards / unconfirmed hint never binds / unreadable number guide / confirmed binding wins / READ_PHONE_NUMBERS optional\n")
   finish(Activity.RESULT_OK,out)
  }catch(t:Throwable){out.putString("stream","FAIL "+t.stackTraceToString());finish(Activity.RESULT_CANCELED,out)}
 }
}
