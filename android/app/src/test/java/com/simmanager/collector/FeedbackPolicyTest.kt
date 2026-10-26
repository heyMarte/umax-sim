package com.simmanager.collector
import org.junit.Test
import org.junit.Assert.*
class FeedbackPolicyTest {
 @Test fun successRequiresLocalPhysicalReceiptUploadAndAssignment(){assertEquals("success",receiveCheckOutcome(true,true,true,false));assertEquals("waiting",receiveCheckOutcome(false,true,true,false));assertEquals("failure",receiveCheckOutcome(true,true,false,false));assertEquals("expired",receiveCheckOutcome(false,false,false,true));assertEquals("waiting",receiveCheckOutcome(true,false,false,false))}
 @Test fun terminalStopsPollingEvenWhenAnAsyncReplyReturns(){assertTrue(receiveCheckShouldPoll(false,false,false));assertFalse(receiveCheckShouldPoll(false,true,false));assertFalse(receiveCheckShouldPoll(true,false,false));assertFalse(receiveCheckShouldPoll(false,false,true))}
 @Test fun feedbackIsScopedToRequestAndGeneration(){assertTrue(remoteFeedbackVisible(7,"g","g"));assertFalse(remoteFeedbackVisible(0,"g","g"));assertFalse(remoteFeedbackVisible(7,"old","new"));assertEquals("7:failed",remoteFeedbackSignature(7,"failed"));assertEquals(remoteFeedbackSignature(7,"failed"),remoteFeedbackSignature(7,"failed"))}
}
