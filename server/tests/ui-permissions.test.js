import test from 'node:test';
import assert from 'node:assert/strict';
import {hasPermission,permittedPages} from '../public/static/ui-data.js';
test('operators receive only explicitly assigned rights',()=>{
 assert.equal(hasPermission({role:'operator'},'messages.view'),false);
 assert.equal(hasPermission({role:'operator',permissions:['messages.view']},'messages.view'),true);
 assert.equal(hasPermission({role:'operator',permissions:['messages.view']},'messages.copy'),false);
 assert.equal(hasPermission({role:'operator',permissions:['users.permissions']},'users.delete'),false);
 assert.equal(hasPermission(null,'messages.view'),false);
});
test('superusers bypass rights; empty and SMS-only accounts have safe menus',()=>{
 const pages={overview:'dashboard.view',messages:'messages.view',devices:'devices.view',users:'users.view'};
 assert.deepEqual(permittedPages({role:'admin'},pages),Object.keys(pages));
 assert.deepEqual(permittedPages({role:'operator',permissions:[]},pages),[]);
 assert.deepEqual(permittedPages({role:'operator',permissions:['messages.view']},pages),['messages']);
});
test('action-only accounts can open useful creation screens without listing rights',()=>{
 const pages={sims:['sims.view','sims.create'],devices:['devices.view','devices.create'],records:['records.view','records.balances.create','records.recharges.create'],users:['users.view','users.create']};
 for(const [permission,page] of [['sims.create','sims'],['devices.create','devices'],['records.balances.create','records'],['records.recharges.create','records'],['users.create','users']]){
  const me={role:'operator',permissions:[permission]};
  assert.deepEqual(permittedPages(me,pages),[page]);
  assert.equal(hasPermission(me,page+'.view'),false);
 }
});

test('notification links select only a page permitted for the current account',async()=>{
 const {requestedPage}=await import('../public/static/ui-data.js');
 assert.equal(requestedPage('#messages',['overview','messages']),'messages');
 assert.equal(requestedPage('#users',['messages']),null);
 assert.equal(requestedPage('#messages?token=private',['messages']),null);
 assert.equal(requestedPage('#%3Cscript%3E',['overview']),null);
});
