package com.simmanager.collector
import org.junit.Test
import org.junit.Assert.*
class UpdatePolicyTest {
 @Test fun publishedSameOrOlderVersionIsUpToDate(){assertFalse(UpdatePolicy.newer(9,9));assertFalse(UpdatePolicy.newer(8,9));assertTrue(UpdatePolicy.newer(10,9))}
 @Test fun validatesManifestAndVersion(){assertTrue(UpdatePolicy.valid(9,8,1,"a".repeat(64),"/api/device/app-update/download"));assertFalse(UpdatePolicy.valid(8,8,1,"a".repeat(64),"/api/device/app-update/download"));assertFalse(UpdatePolicy.valid(9,8,67108865,"a".repeat(64),"/api/device/app-update/download"));assertFalse(UpdatePolicy.valid(9,8,1,"bad","/api/device/app-update/download"))}
 @Test fun rejectsExternalAndQueryDownloads(){for(p in listOf("https://evil.test/a","//evil.test/a","/api/device/app-update/download?x=1"))assertFalse(UpdatePolicy.valid(9,8,1,"a".repeat(64),p))}
 @Test fun checksDigestAndExactSize(){assertTrue(UpdatePolicy.content(3,3,"abc","abc"));assertFalse(UpdatePolicy.content(2,3,"abc","abc"));assertFalse(UpdatePolicy.content(3,3,"abc","def"))}
}
