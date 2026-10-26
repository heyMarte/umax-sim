package com.simmanager.collector
import org.junit.Assert.*
import org.junit.Test
class ConnectionConfigTest {
 @Test fun pastedConfigKeepsServerAndKeyTogether(){val key="a".repeat(64);val p=ConnectionConfig.parse("SIMMANAGER\nhttps://sim.example.com/\n$key")!!;assertEquals("https://sim.example.com",p.url);assertEquals(key,p.token)}
 @Test fun rejectsWrongProtocolPathsAndCredentials(){for(s in listOf("http://sim.example.com","https://sim.example.com/api","https://user@sim.example.com","https://","https://sim.example.com?x=1"))assertNull(ConnectionConfig.url(s))}
 @Test fun rejectsArbitraryClipboardText(){assertNull(ConnectionConfig.parse("unrelated clipboard text"));assertNotNull(ConnectionConfig.parse("SIMMANAGER\nhttps://sim.example.com\nshort"))}
 @Test fun oldResponseCannotUpdateNewConfiguration(){assertFalse(syncGenerationMatches("old","new",true));assertFalse(syncGenerationMatches("old","old",false));assertTrue(syncGenerationMatches("old","old",true))}
 @Test fun customKeysHaveNoStrengthOrCharsetRestriction(){for(key in listOf("1","123456","中文 密钥!@#", "a".repeat(1000))){val encoded=java.util.Base64.getEncoder().encodeToString(key.toByteArray(Charsets.UTF_8));val config=ConnectionConfig.parse("SIMMANAGER2\nhttps://sim.example.com\n$encoded\n42")!!;assertEquals(key,config.token);assertEquals(42L,config.deviceId);assertEquals("DeviceKey $encoded",deviceAuthorization(key));assertEquals(key,ConnectionConfig.parse("SIMMANAGER\nhttps://sim.example.com\n$key")!!.token)}}
 @Test fun modernClipboardRejectsInvalidIdsAndEncoding(){for(id in listOf("0","-1","abc","9223372036854775808"))assertNull(ConnectionConfig.parse("SIMMANAGER2\nhttps://sim.example.com\nMTIzNDU2\n$id"));assertNull(ConnectionConfig.parse("SIMMANAGER2\nhttps://sim.example.com\n%%%\n1"));assertNull(ConnectionConfig.parse("SIMMANAGER2\nhttps://sim.example.com\n\n1"));assertNull(ConnectionConfig.parse("SIMMANAGER2\nhttps://sim.example.com\n/w==\n1"));assertNull(ConnectionConfig.parse("SIMMANAGER\nhttps://sim.example.com\n"));assertNull(ConnectionConfig.parse("SIMMANAGER\nhttps://sim.example.com\n123456")!!.deviceId)}
 @Test fun recentSuccessDoesNotHideStoppedServiceOrLaterFailure(){assertEquals("采集未运行",syncLabel(false,9000,8000,false,10000));assertEquals("最近同步未成功",syncLabel(true,9000,9500,false,10000));assertEquals("已连接后台",syncLabel(true,9000,8000,false,10000));assertEquals("同步已中断",syncLabel(true,1000,1000,false,200000));assertEquals("正在同步",syncLabel(true,1000,2000,true,3000))}
 @Test fun hiddenIdentityFollowsExactCredentials(){
  val imported=PairingConfig("https://sim.example.com","中文 key",43)
  assertEquals(43L,resolvePairingDeviceId(imported.url,imported.token,imported.url,imported.token,42,imported))
  assertEquals(42L,resolvePairingDeviceId(imported.url,imported.token,imported.url,imported.token,42,null))
  assertEquals(0L,resolvePairingDeviceId("https://new.example.com",imported.token,imported.url,imported.token,42,imported))
  assertEquals(0L,resolvePairingDeviceId(imported.url,"other",imported.url,imported.token,42,imported))
  assertEquals(0L,resolvePairingDeviceId(imported.url,imported.token,imported.url,imported.token,42,PairingConfig(imported.url,imported.token)))
 }
 @Test fun downloadQrAndArbitraryUrlsAreNotConfiguration(){
  for(value in listOf("https://sim.example.com/a","https://sim.example.com","SIMMANAGER2\nhttp://sim.example.com\nMTIzNDU2\n42"))assertNull(ConnectionConfig.parse(value))
 }
 @Test fun realQrBitmapRoundTripKeepsUnicodeAndHiddenIdentity(){
  val token="中文 密钥!@#"
  val raw="SIMMANAGER2\nhttps://sim.example.com\n"+java.util.Base64.getEncoder().encodeToString(token.toByteArray(Charsets.UTF_8))+"\n42"
  val matrix=com.google.zxing.qrcode.QRCodeWriter().encode(raw,com.google.zxing.BarcodeFormat.QR_CODE,600,600)
  val pixels=IntArray(600*600){i->if(matrix[i%600,i/600])0xff000000.toInt() else 0xffffffff.toInt()}
  val bitmap=com.google.zxing.BinaryBitmap(com.google.zxing.common.HybridBinarizer(com.google.zxing.RGBLuminanceSource(600,600,pixels)))
  val decoded=com.google.zxing.qrcode.QRCodeReader().decode(bitmap).text
  assertEquals(raw,decoded);assertEquals(token,ConnectionConfig.parse(decoded)!!.token);assertEquals(42L,ConnectionConfig.parse(decoded)!!.deviceId)
 }
}
