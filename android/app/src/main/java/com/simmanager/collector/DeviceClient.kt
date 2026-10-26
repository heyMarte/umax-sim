package com.simmanager.collector

import android.content.Context
import org.json.JSONObject
import java.net.URL
import javax.net.ssl.HttpsURLConnection

/** A request belongs to one configuration and one caller lifetime. */
class DeviceClient(context:Context,private val alive:()->Boolean={true}) {
 private val prefs=context.getSharedPreferences("config",0)
 private val generation=prefs.getString("config_generation","legacy")
 private val endpoint=configuredUrl(prefs.getString("url",null))
 private val deviceId=prefs.getLong("server_device_id",0)
 private val sealed=prefs.getString("token","")?:""
 fun current()=deviceId==prefs.getLong("server_device_id",0)&&alive()&&prefs.getBoolean("enabled",false)&&generation==prefs.getString("config_generation","legacy")&&endpoint==configuredUrl(prefs.getString("url",null))&&sealed==prefs.getString("token","")
 fun post(path:String,body:JSONObject):JSONObject {
  check(ConnectionConfig.url(endpoint)!=null){"请先扫码或填写自己的 HTTPS 后台地址"}
  check(current()){"配置已变更或采集已停止，请重新开始"}
  val c=URL("$endpoint/api/device/$path").openConnection() as HttpsURLConnection
  try {
   c.requestMethod="POST";c.connectTimeout=10000;c.readTimeout=10000;c.instanceFollowRedirects=false;c.doOutput=true
   c.setRequestProperty("Content-Type","application/json");c.setRequestProperty("Authorization",deviceAuthorization(Vault.open(sealed)))
   if(deviceId>0)c.setRequestProperty("X-Device-Id",deviceId.toString())
   c.outputStream.use{it.write(body.toString().toByteArray(Charsets.UTF_8))}
   check(c.responseCode==200){"后台返回 HTTP ${c.responseCode}，请检查网络、地址、设备密钥；密钥重复时请扫码或粘贴连接配置"}
   val result=JSONObject(c.inputStream.bufferedReader().use{it.readText()})
   check(current()){"配置已变更或采集已停止，请重新开始"}
   check(result.optBoolean("ok",true)){"后台未接受请求"}
   return result
  }finally{c.disconnect()}
 }
}
fun checkMarkerMatches(body:String,code:String)=code.matches(Regex("[0-9A-Fa-f]{6}"))&&Regex("(?i)(?<![A-Z0-9])SIMTEST-"+code+"(?![A-Z0-9])").containsMatchIn(body)
