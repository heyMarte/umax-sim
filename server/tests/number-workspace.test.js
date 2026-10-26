import test from 'node:test';
import assert from 'node:assert/strict';
import {numberMatches} from '../public/static/ui-data.js';
test('number search matches formatted phone or any visible platform name',()=>{
 const sim={phone:'+639171234567',carrier:'Globe',shops:[{name:'Motor Hub'},{name:'家居专营店'},{name:'Lazada Tools'}]};
 assert.equal(numberMatches(sim,'0917 123-4567'),true);
 assert.equal(numberMatches(sim,'+63 917 1234567'),true);
 assert.equal(numberMatches(sim,'1234567'),true);
 assert.equal(numberMatches(sim,'家居'),true);
 assert.equal(numberMatches(sim,'lazada tools'),true);
 assert.equal(numberMatches(sim,'其他店'),false);
 assert.equal(numberMatches({...sim,shop:'HIDDEN_LEGACY',shops:[]},'HIDDEN_LEGACY'),false);
});
