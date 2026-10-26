package com.simmanager.collector

import android.content.Context
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper
import android.content.ContentValues
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec
import java.security.KeyStore
import org.json.JSONObject

// SQLiteOpenHelper only implements AutoCloseable on API 29+. Declare a stable
// interface ourselves so Kotlin use{} is safe on our API 26–28 runtime floor.
class Queue(context: Context): SQLiteOpenHelper(context,"queue.db",null,3), java.io.Closeable {
 override fun onCreate(db: SQLiteDatabase) { db.execSQL("CREATE TABLE quarantine(id TEXT PRIMARY KEY,payload TEXT NOT NULL,enqueued_at INTEGER NOT NULL,reason TEXT NOT NULL)");db.execSQL("CREATE TABLE queue(id TEXT PRIMARY KEY,payload TEXT NOT NULL,enqueued_at INTEGER NOT NULL)") }
 override fun onUpgrade(db: SQLiteDatabase,old: Int,new: Int) { if(old<2) { db.execSQL("ALTER TABLE queue ADD COLUMN enqueued_at INTEGER NOT NULL DEFAULT 0") }; if(old<3)db.execSQL("CREATE TABLE IF NOT EXISTS quarantine(id TEXT PRIMARY KEY,payload TEXT NOT NULL,enqueued_at INTEGER NOT NULL,reason TEXT NOT NULL)") }
 fun add(id: String,event: JSONObject) { writableDatabase.insertOrThrow("queue",null,ContentValues().apply { put("id",id);put("payload",event.toString());put("enqueued_at",System.currentTimeMillis()) }) }
 fun pending(): List<JSONObject> { val out=mutableListOf<JSONObject>(); readableDatabase.rawQuery("SELECT payload FROM queue ORDER BY enqueued_at,id LIMIT 100",null).use { while(it.moveToNext())out.add(JSONObject(it.getString(0))) };return out }
 fun ack(id: String) { writableDatabase.delete("queue","id=?", arrayOf(id)) }
 fun oldestAge(): Long? {
  val db=readableDatabase
  if(db.compileStatement("SELECT COUNT(*) FROM queue WHERE enqueued_at<=0").simpleQueryForLong()>0)return null
  val oldest=db.compileStatement("SELECT COALESCE(MIN(enqueued_at),0) FROM queue").simpleQueryForLong()
  return if(oldest>0)((System.currentTimeMillis()-oldest)/1000).coerceAtLeast(0) else 0
 }
 fun quarantine(id:String,reason:String){val db=writableDatabase;db.beginTransaction();try{db.execSQL("INSERT OR REPLACE INTO quarantine SELECT id,payload,enqueued_at,? FROM queue WHERE id=?",arrayOf(reason,id));db.delete("queue","id=?",arrayOf(id));db.setTransactionSuccessful()}finally{db.endTransaction()}}
 fun rejected():List<JSONObject>{val out=mutableListOf<JSONObject>();readableDatabase.rawQuery("SELECT payload,reason FROM quarantine ORDER BY enqueued_at DESC LIMIT 50",null).use{while(it.moveToNext())out.add(JSONObject(it.getString(0)).put("rejection_reason",it.getString(1)))};return out}
 fun rejectedCount():Long=readableDatabase.compileStatement("SELECT COUNT(*) FROM quarantine").simpleQueryForLong()
 fun retryRejected(){val db=writableDatabase;db.beginTransaction();try{db.execSQL("INSERT OR IGNORE INTO queue SELECT id,payload,enqueued_at FROM quarantine");db.delete("quarantine",null,null);db.setTransactionSuccessful()}finally{db.endTransaction()}}
 fun clear() { val db=writableDatabase;db.beginTransaction();try{db.delete("queue",null,null);db.delete("quarantine",null,null);db.setTransactionSuccessful()}finally{db.endTransaction()} }
 fun count(): Long = readableDatabase.compileStatement("SELECT COUNT(*) FROM queue").simpleQueryForLong()
}
object Vault {
 private fun key(): SecretKey { val ks=KeyStore.getInstance("AndroidKeyStore").apply{load(null)};if(!ks.containsAlias("sim-token")){KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES,"AndroidKeyStore").apply{init(KeyGenParameterSpec.Builder("sim-token",KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT).setBlockModes(KeyProperties.BLOCK_MODE_GCM).setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE).build());generateKey()}};return ks.getKey("sim-token",null) as SecretKey }
 fun seal(value:String):String { val c=Cipher.getInstance("AES/GCM/NoPadding");c.init(Cipher.ENCRYPT_MODE,key());return Base64.encodeToString(c.iv+c.doFinal(value.toByteArray()),Base64.NO_WRAP) }
 fun open(value:String):String { val b=Base64.decode(value,Base64.NO_WRAP);val c=Cipher.getInstance("AES/GCM/NoPadding");c.init(Cipher.DECRYPT_MODE,key(),GCMParameterSpec(128,b.copyOfRange(0,12)));return String(c.doFinal(b.copyOfRange(12,b.size))) }
}
