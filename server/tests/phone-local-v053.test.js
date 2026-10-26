import test from 'node:test';
import assert from 'node:assert/strict';
import {normalizePhone,localPhone} from '../public/static/ui-data.js';

test('PH local mobile entry canonicalizes all supported exact formats',()=>{
 for(const value of ['9171234567','09171234567','+639171234567','639171234567','(0917) 123-4567','+63 917 123-4567'])assert.equal(normalizePhone(value,'PH'),'+639171234567');
 for(const value of ['9171234567','09171234567','+639171234567','639171234567'])assert.equal(localPhone(value,'PH'),'9171234567');
});
test('CN local mobile entry and edit representation are consistent',()=>{
 for(const value of ['13812345678','+8613812345678','8613812345678','+86 138-1234-5678'])assert.equal(normalizePhone(value,'CN'),'+8613812345678');
 assert.equal(localPhone('+8613812345678','CN'),'13812345678');
});
test('wrong countries and malformed prefixes are never silently stripped',()=>{
 for(const [value,country] of [['+8613812345678','PH'],['+639171234567','CN'],['63917123456','PH'],['+6391712345678','PH'],['+63917x234567','PH'],['+1 9171234567','PH']]){
  const compact=value.replace(/[\s()-]/g,'');
  assert.equal(normalizePhone(value,country),compact);
  assert.equal(localPhone(value,country),value);
 }
 assert.equal(normalizePhone('', 'PH'),'');
});
