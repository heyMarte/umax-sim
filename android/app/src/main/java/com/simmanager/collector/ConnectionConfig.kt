package com.simmanager.collector
import java.net.URI
class PairingConfig(val url:String,val token:String,val deviceId:Long?=null){override fun toString()="PairingConfig(secret hidden)"}
object ConnectionConfig {
 fun url(value:String):String? = runCatching{
  val normalized=value.trim().trimEnd('/')
  val u=URI(normalized)
  if(u.scheme!="https"||u.host.isNullOrBlank()||u.userInfo!=null||u.rawQuery!=null||u.rawFragment!=null||!u.path.isNullOrEmpty()||u.port==0||u.port>65535)return null
  normalized
 }.getOrNull()
 fun parse(value:String):PairingConfig? {
  val lines=value.trimEnd('\r','\n').lines()
  val modern=lines.firstOrNull()=="SIMMANAGER2"
  if(lines.size!=(if(modern)4 else 3)||(!modern&&lines[0]!="SIMMANAGER"))return null
  val address=url(lines[1])?:return null
  val token=if(modern)runCatching{
   val bytes=java.util.Base64.getDecoder().decode(lines[2])
   Charsets.UTF_8.newDecoder().decode(java.nio.ByteBuffer.wrap(bytes)).toString()
  }.getOrNull()?:return null else lines[2]
  if(token.isEmpty())return null
  val id=if(modern)positiveDeviceId(lines[3])?:return null else null
  return PairingConfig(address,token,id)
 }
}
fun syncLabel(running:Boolean,lastSuccess:Long,lastAttempt:Long,busy:Boolean,now:Long):String {
 if(!running)return "采集未运行"
 if(busy)return "正在同步"
 if(lastSuccess<=0)return "等待首次同步"
 if(lastAttempt>lastSuccess)return "最近同步未成功"
 if(now-lastSuccess>180000)return "同步已中断"
 return "已连接后台"
}
fun syncGenerationMatches(captured:String,current:String,enabled:Boolean)=enabled&&captured==current

fun pendingRefresh(requestId:Long,lastAck:Long)=requestId>0&&requestId>lastAck
fun localCheckMatches(sub:Int,expectedSub:Int,generation:String,expectedGeneration:String,now:Long,expiry:Long,body:String,code:String)=sub>=0&&sub==expectedSub&&generation==expectedGeneration&&now<expiry&&checkMarkerMatches(body,code)

fun refreshAckForGeneration(ack:Long,ackGeneration:String?,currentGeneration:String?)=if(ackGeneration==currentGeneration)ack else 0L
fun checkAssignmentLabel(received:Boolean,assigned:Boolean)=when{!received->"等待上传后核对";assigned->"已确认";else->"未匹配：本次短信已上传，但号码 / 店铺未绑定或与测试开始时不一致，请检查后台所选卡槽"}

fun localCheckDeadline(localNow:Long,serverStart:Long,serverExpiry:Long)=localNow+(serverExpiry-serverStart).coerceIn(0L,600L)*1000

fun positiveDeviceId(value:String):Long?=value.toLongOrNull()?.takeIf{it>0}
fun deviceAuthorization(token:String)="DeviceKey "+java.util.Base64.getEncoder().encodeToString(token.toByteArray(Charsets.UTF_8))

/** An imported identity is valid only for its exact credentials. */
fun resolvePairingDeviceId(address:String,token:String,savedUrl:String?,savedToken:String?,savedId:Long,imported:PairingConfig?):Long {
 if(imported!=null&&imported.url==address&&imported.token==token)return imported.deviceId?:0L
 return if(address==savedUrl&&token==savedToken)savedId else 0L
}
