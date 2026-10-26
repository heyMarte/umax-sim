package com.simmanager.collector
import org.junit.Assert.*
import org.junit.Test
class ReceiveCheckTest {
 @Test fun configChangeCannotCarryOldRefreshAck(){assertEquals(601000L,localCheckDeadline(1000,900000,900600));assertEquals(601000L,localCheckDeadline(1000,1,999999));assertEquals(1000L,localCheckDeadline(1000,200,100));assertEquals(0L,refreshAckForGeneration(999,"old","new"));assertEquals(999L,refreshAckForGeneration(999,"new","new"));assertTrue(pendingRefresh(1,refreshAckForGeneration(999,"old","new")))}
 @Test fun waitingUploadDoesNotAskAlreadyBoundCardToBind(){assertEquals("等待上传后核对",checkAssignmentLabel(false,false));assertEquals("已确认",checkAssignmentLabel(true,true));assertTrue(checkAssignmentLabel(true,false).contains("未匹配"))}
 @Test fun markerRequiresSixHexAndWholeMarker(){
  assertTrue(checkMarkerMatches("测试 simtest-aB12ef，谢谢","AB12EF"))
  for(body in listOf("SIMTEST-AB12EF0","XSIMTEST-AB12EF","普通验证码 123456","SIMTEST-AB12E"))assertFalse(checkMarkerMatches(body,"AB12EF"))
  assertFalse(checkMarkerMatches("SIMTEST-......","......"))
 }
 @Test fun unrelatedCardExpiredOrOldConfigCannotPassLocalReceipt(){
  fun matches(sub:Int=4,expected:Int=4,generation:String="new",now:Long=100,expiry:Long=200)=localCheckMatches(sub,expected,generation,"new",now,expiry,"SIMTEST-ABC123","ABC123")
  assertTrue(matches());assertFalse(matches(sub=5));assertFalse(matches(sub=-1,expected=-1));assertFalse(matches(generation="old"));assertFalse(matches(now=200));assertFalse(matches(expiry=0))
 }
 @Test fun pollingIgnoresNullDecodedZeroDuplicateAndOlderCommands(){
  assertTrue(pendingRefresh(8,7));assertFalse(pendingRefresh(0,0));assertFalse(pendingRefresh(7,7));assertFalse(pendingRefresh(6,7));assertFalse(pendingRefresh(-1,0))
 }
}
