package com.simmanager.collector
fun receiveCheckOutcome(local:Boolean,uploaded:Boolean,assigned:Boolean,expired:Boolean)=when{local&&uploaded&&assigned->"success";uploaded&&!assigned->"failure";expired->"expired";else->"waiting"}
fun receiveCheckShouldPoll(closed:Boolean,terminal:Boolean,busy:Boolean)=!closed&&!terminal&&!busy
fun remoteFeedbackVisible(id:Long,generation:String,current:String)=id>0&&generation==current
fun remoteFeedbackSignature(id:Long,state:String)="$id:$state"
