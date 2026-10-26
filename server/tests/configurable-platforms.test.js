import test from 'node:test';import assert from 'node:assert/strict';
import * as data from '../public/static/ui-data.js';
test('server platform catalog extends shared options for filtering and display',()=>{
 const identity=data.platformOptions;
 try{
  data.configurePlatforms([...identity,{value:'amazon',label:'Amazon <b>plain</b>',glyph:'A'}]);
  assert.equal(data.platformOptions,identity);
  assert.deepEqual(data.normalizePlatforms(['amazon','tiktok']),['tiktok','amazon']);
  assert.equal(data.messagePlatform({inferred_platform:'amazon'}).label,'Amazon <b>plain</b>');
  assert.equal(data.shopMatchesPlatform({platform:'amazon'},'amazon'),true);
  assert.deepEqual(data.normalizePlatforms(['amazon','bogus']),['amazon']);
  const old=identity.map(x=>({...x}));assert.throws(()=>data.configurePlatforms([{value:'<bad>',label:'unsafe'}]));assert.deepEqual(identity,old);
  assert.equal(data.messagePlatform({inferred_platform:'retired_tag',platform_label:'retired_tag'}).label,'retired_tag');
 }finally{data.configurePlatforms([{value:'tiktok',label:'TikTok',glyph:'T'},{value:'shopee',label:'Shopee',glyph:'S'},{value:'lazada',label:'Lazada',glyph:'L'}]);}
});
