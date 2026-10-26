package com.simmanager.collector

import org.junit.Assert.*
import org.junit.Test
import java.io.File
import java.nio.file.Files
import java.security.MessageDigest

class LegacyUpdateFilesTest {
 private fun hash(bytes:ByteArray)=MessageDigest.getInstance("SHA-256").digest(bytes).joinToString(""){"%02x".format(it)}
 @Test fun oldAndroidDefaultsToFileAndNewAndroidCanExplicitlyFallBack(){
  for(sdk in 23..25)assertTrue(UpdateHandoffPolicy.systemFile(sdk,false))
  for(sdk in listOf(26,28,35)){assertFalse(UpdateHandoffPolicy.systemFile(sdk,false));assertTrue(UpdateHandoffPolicy.systemFile(sdk,true))}
 }
 @Test fun downloadPageUsesOnlySavedHttpsOrigin(){
  assertEquals("https://example.org:8443/a",UpdateHandoffPolicy.downloadPage("https://example.org:8443/"))
  for(s in listOf("http://example.org","https://user@example.org","https://example.org/evil","https://example.org?q=secret","https://example.org#fragment"))assertNull(UpdateHandoffPolicy.downloadPage(s))
 }
 @Test fun stagedCopyIsPrivateDistinctAndVerifiedIndependently(){
  val root=Files.createTempDirectory("update-stage").toFile()
  try{val source=File(root,"source.apk").apply{writeBytes(byteArrayOf(1,2,3,4))};val store=UpdateFileStore(File(root,"verified-updates")){1000L}
   val first=store.stage(source,4,hash(source.readBytes()));val second=store.stage(source,4,hash(source.readBytes()))
   assertNotEquals(first,second);assertEquals(File(root,"verified-updates").canonicalFile,first.parentFile.canonicalFile);assertArrayEquals(source.readBytes(),first.readBytes())
   source.delete();assertTrue(first.isFile);assertTrue(second.isFile)
   first.appendBytes(byteArrayOf(5));assertFalse(store.verified(first,4,hash(byteArrayOf(1,2,3,4))))
  }finally{root.deleteRecursively()}
 }
 @Test fun rejectsSizeOrDigestMismatchWithoutLeavingAnApk(){
  val root=Files.createTempDirectory("update-reject").toFile()
  try{val source=File(root,"source.apk").apply{writeText("candidate")};val store=UpdateFileStore(File(root,"verified-updates")){1000L}
   for((size,digest)in listOf(1L to hash(source.readBytes()),source.length() to "0".repeat(64))){try{store.stage(source,size,digest);fail("Corrupt update staged")}catch(_:IllegalStateException){}}
   assertEquals(0,File(root,"verified-updates").listFiles()?.size?:0)
  }finally{root.deleteRecursively()}
 }
 @Test fun laterCleanupKeepsPendingAndRecentFilesAndBoundsDeletion(){
  val root=Files.createTempDirectory("update-cleanup").toFile()
  try{val now=UpdateFileStore.TTL+5000;val store=UpdateFileStore(root){now};val active=File(root,"install-active.apk").apply{writeText("active");setLastModified(1)};val recent=File(root,"install-recent.apk").apply{writeText("recent");setLastModified(now)}
   repeat(40){File(root,"install-old-$it.apk").apply{writeText("old");setLastModified(1)}}
   store.cleanup(active.name);assertTrue(active.isFile);assertTrue(recent.isFile);assertEquals(8,root.listFiles()!!.count{it.name.startsWith("install-old-")})
   store.cleanup(null);assertFalse(active.exists());assertTrue(recent.exists())
  }finally{root.deleteRecursively()}
 }
}
