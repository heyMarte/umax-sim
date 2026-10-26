package com.simmanager.collector
object UpdatePolicy {
 const val MAX_SIZE=64L*1024*1024
 const val PATH="/api/device/app-update/download"
 fun newer(published:Long,installed:Long)=published>installed
 fun valid(version:Long,installed:Long,size:Long,hash:String,path:String)=version>installed&&size in 1..MAX_SIZE&&hash.matches(Regex("[a-fA-F0-9]{64}"))&&path==PATH
 fun content(actual:Long,expected:Long,hash:String,expectedHash:String)=actual==expected&&hash.equals(expectedHash,true)
}
