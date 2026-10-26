package com.simmanager.collector

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.PackageManager
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.*
import android.telephony.*
import org.json.*

/** Capability based snapshots. Null means the platform did not expose the value. */
class TelemetryCollector(private val c:Context) {
 private fun permitted(p:String)=c.checkSelfPermission(p)==PackageManager.PERMISSION_GRANTED
 private fun safe(block:()->Any?):Any=try{block()?:JSONObject.NULL}catch(_:Exception){JSONObject.NULL}
 fun collect():JSONObject = collectSnapshot().payload
 internal fun collectSnapshot():ObservedTelemetry = SimLifecycle.locked {
  val generation=c.getSharedPreferences("config",0).getString("config_generation","legacy")?:"legacy"
  val raw=try { sample() } catch(_:Exception) { JSONObject().put("subscriptions",JSONArray()).put("subscriptions_status","unavailable").put("telemetry",JSONObject()) }
  ObservedTelemetry(raw,SimLifecycle.observe(c,raw,generation))
 }
 @Suppress("DEPRECATION")
 private fun phoneHint(info:SubscriptionInfo,tm:TelephonyManager?):String? {
  val allowed=canReadSimPhoneHint(Build.VERSION.SDK_INT,permitted(Manifest.permission.READ_PHONE_STATE),
   if(Build.VERSION.SDK_INT>=26)permitted(Manifest.permission.READ_PHONE_NUMBERS) else false,
   c.getSharedPreferences("config",0).getBoolean("legacy_phone_hint_consent",false))
  if(!allowed)return null
  return resolveSimPhoneHint(Build.VERSION.SDK_INT,
   {try{info.number}catch(_:SecurityException){null}catch(_:Exception){null}},
   {
    val modern=if(Build.VERSION.SDK_INT>=33)try{c.getSystemService(SubscriptionManager::class.java).getPhoneNumber(info.subscriptionId)}catch(_:SecurityException){null}catch(_:Exception){null}else null
    normalizeSimPhoneHint(modern)?:try{tm?.line1Number}catch(_:SecurityException){null}catch(_:Exception){null}
   })
 }
 private fun sample():JSONObject {
  val p=c.getSharedPreferences("config",0);val t=JSONObject().put("collector_running",CollectorService.instance!=null)
  val battery=c.registerReceiver(null,IntentFilter(Intent.ACTION_BATTERY_CHANGED))
  val level=battery?.getIntExtra(BatteryManager.EXTRA_LEVEL,-1)?:-1;val scale=battery?.getIntExtra(BatteryManager.EXTRA_SCALE,-1)?:-1
  t.put("battery_percent",if(level>=0&&scale>0&&level<=scale)level*100/scale else JSONObject.NULL)
  t.put("charging",battery?.let{it.getIntExtra(BatteryManager.EXTRA_PLUGGED,0)!=0}?:JSONObject.NULL)
  t.put("battery_temp_c",battery?.takeIf{it.hasExtra(BatteryManager.EXTRA_TEMPERATURE)}?.getIntExtra(BatteryManager.EXTRA_TEMPERATURE,0)?.div(10.0)?.takeIf{it in -50.0..120.0}?:JSONObject.NULL)
  val cm=c.getSystemService(ConnectivityManager::class.java);val caps=cm.getNetworkCapabilities(cm.activeNetwork)
  val transport=when {cm.activeNetwork==null->"none";caps==null->"unknown";caps.hasTransport(NetworkCapabilities.TRANSPORT_VPN)->"vpn";caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI)->"wifi";caps.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR)->"cellular";caps.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET)->"ethernet";else->"other"}
  t.put("network_transport",transport).put("internet_validated",caps?.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED)?:JSONObject.NULL)
  t.put("manufacturer",Build.MANUFACTURER).put("model",Build.MODEL).put("android_version",Build.VERSION.RELEASE).put("android_sdk",Build.VERSION.SDK_INT).put("app_version",BuildConfig.VERSION_NAME).put("uptime_seconds",SystemClock.elapsedRealtime()/1000)
  Queue(c).use{t.put("quarantined_count",it.rejectedCount()).put("pending_count",it.count()).put("oldest_pending_age_seconds",it.oldestAge()?:JSONObject.NULL)}
  for(key in listOf("last_success_at","last_received_at","last_sms_upload_at"))t.put(key,if(p.getLong(key,0)>0)p.getLong(key,0)/1000 else JSONObject.NULL)
  t.put("sync_duration_ms",if(p.contains("sync_duration_ms"))p.getLong("sync_duration_ms",0) else JSONObject.NULL).put("consecutive_failures",p.getInt("consecutive_failures",0)).put("last_error",p.getString("last_error","")?.take(300))
  t.put("permissions",JSONObject().put("sms",permitted(Manifest.permission.RECEIVE_SMS)).put("phone",permitted(Manifest.permission.READ_PHONE_STATE)).put("phone_numbers",canReadSimPhoneHint(Build.VERSION.SDK_INT,permitted(Manifest.permission.READ_PHONE_STATE),if(Build.VERSION.SDK_INT>=26)permitted(Manifest.permission.READ_PHONE_NUMBERS) else false,p.getBoolean("legacy_phone_hint_consent",false))).put("location",permitted(Manifest.permission.ACCESS_COARSE_LOCATION)).put("notifications",androidx.core.app.NotificationManagerCompat.from(c).areNotificationsEnabled()))
  val power=c.getSystemService(PowerManager::class.java)
  t.put("power_save",safe{power.isPowerSaveMode}).put("battery_optimization_ignored",safe{power.isIgnoringBatteryOptimizations(c.packageName)})
  return collectSubscriptions().put("telemetry",t)
 }
 internal fun collectSubscriptions():JSONObject {
  val subs=JSONArray();var status="ok"
  try {val active=c.getSystemService(SubscriptionManager::class.java).activeSubscriptionInfoList
   val permission=permitted(Manifest.permission.READ_PHONE_STATE)
   val slots=if(Build.VERSION.SDK_INT>=26&&active==null&&permission)runCatching{val tm=c.getSystemService(TelephonyManager::class.java);(0 until tm.phoneCount).map{tm.getSimState(it)}}.getOrNull() else null
   status=subscriptionSnapshotStatus(active!=null,permission,slots)
   active?.forEach{info->
   val tm=if(Build.VERSION.SDK_INT>=24)c.getSystemService(TelephonyManager::class.java).createForSubscriptionId(info.subscriptionId)else null
   val s=JSONObject().put("subscription_id",info.subscriptionId).put("slot",if(info.simSlotIndex>=0)info.simSlotIndex+1 else JSONObject.NULL).put("carrier",info.carrierName).put("sim_state",if(Build.VERSION.SDK_INT>=26)safe{tm?.getSimState(info.simSlotIndex)}else JSONObject.NULL)
   // The active list identifies this exact subscription and slot even when no public per-slot state API exists.
   if(Build.VERSION.SDK_INT in 23..25)s.put("subscription_active",true).put("sim_state_reason","unsupported_android_version")
   val hint=phoneHint(info,tm)
   s.put("phone_hint",hint?:JSONObject.NULL).put("phone_hint_source",if(hint!=null)"android_sim" else JSONObject.NULL)
   var reason:Any=if(Build.VERSION.SDK_INT<26)"unsupported_android_version" else JSONObject.NULL
   val service=if(Build.VERSION.SDK_INT>=26)try{tm?.serviceState}catch(_:SecurityException){reason="permission_denied";null}catch(_:UnsupportedOperationException){reason="unsupported";null}catch(_:Exception){reason="unavailable";null}else null
   if(service==null&&reason===JSONObject.NULL)reason="unavailable"
   s.put("service_state",when(service?.state){ServiceState.STATE_IN_SERVICE->"in_service";ServiceState.STATE_OUT_OF_SERVICE->"out_of_service";ServiceState.STATE_EMERGENCY_ONLY->"emergency_only";ServiceState.STATE_POWER_OFF->"power_off";else->"unknown"}).put("service_state_reason",reason)
   val signal=if(Build.VERSION.SDK_INT>=28)try{tm?.signalStrength}catch(_:Exception){null}else null
   val dbm=if(Build.VERSION.SDK_INT>=29)signal?.cellSignalStrengths?.map{it.dbm}?.firstOrNull{it in -160..-20}else null
   s.put("signal_level",signal?.level?.takeIf{it in 0..4}?:JSONObject.NULL).put("signal_dbm",dbm?:JSONObject.NULL).put("signal_age_seconds",if(Build.VERSION.SDK_INT>=30&&signal!=null)((SystemClock.elapsedRealtime()-signal.timestampMillis)/1000).coerceAtLeast(0) else JSONObject.NULL)
   // API23 cannot pin TelephonyManager to a subscription. Default-SIM values must never label SIM2.
   s.put("roaming",safe{service?.roaming ?: tm?.networkOperatorName?.takeIf{it.isNotBlank()}?.let{tm.isNetworkRoaming}}).put("network_operator",safe{tm?.networkOperatorName?.takeIf{it.isNotBlank()}}).put("network_country",safe{tm?.networkCountryIso?.takeIf{it.isNotBlank()}}).put("sim_operator",safe{tm?.simOperatorName?.takeIf{it.isNotBlank()}}).put("radio_type",if(Build.VERSION.SDK_INT>=24)safe{tm?.let{radioName(it.dataNetworkType)}}else JSONObject.NULL)
   subs.put(s)
  }}catch(_:SecurityException){status="permission_denied"}catch(_:Exception){status="unavailable"}
  return JSONObject().put("subscriptions",subs).put("subscriptions_status",status)
 }
}

internal fun radioName(type:Int):String?=when(type){1->"2G GPRS";2->"2G EDGE";3->"3G UMTS";4->"CDMA";5,6,12->"3G EVDO";7->"1xRTT";8,9,10,15->"3G HSPA";13->"4G LTE";16->"GSM";17->"TD_SCDMA";18->"IWLAN";20->"5G NR";else->null}
internal fun configuredUrl(saved:String?):String=saved?.let{ConnectionConfig.url(it)}?:""
