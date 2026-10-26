import test from 'node:test';
import assert from 'node:assert/strict';
import {normalizePlatforms,messagePlatform} from '../public/static/ui-data.js';
test('platform membership is ordered and permits three platforms',()=>{assert.deepEqual(normalizePlatforms('["lazada","shopee","tiktok","fake","tiktok"]'),['tiktok','shopee','lazada']);assert.deepEqual(normalizePlatforms(null),[]);assert.deepEqual(normalizePlatforms('broken'),[])});
test('number association never substitutes for a message source',()=>{assert.equal(messagePlatform({platforms:['tiktok','shopee','lazada']}),null);assert.equal(messagePlatform({platforms:['tiktok','shopee','lazada'],inferred_platform:'shopee'}).label,'Shopee')});

import {pairingText} from '../public/static/ui-data.js';
test('pairing preserves simple and Unicode keys and includes distinct device identity',()=>{
 for(const token of ['123456','1','中文 密钥 😃',' leading and trailing ']){
  const lines=pairingText(42,token,'https://sim.example.com').split('\n');
  assert.equal(lines[0],'SIMMANAGER2');assert.equal(lines[1],'https://sim.example.com');
  assert.equal(Buffer.from(lines[2],'base64').toString('utf8'),token);assert.equal(lines[3],'42');
 }
});
