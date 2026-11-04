const assert = require('node:assert/strict');
const {canonical, signature} = require('../assets/workspace-shell.js');
const base = 'https://store.example/wp-admin/admin.php?page=pc-overview';
let checks = 0;
for (const path of ['admin.php?page=pc-overview','admin.php?page=pc-data','admin.php?page=pc-data-products&range=7d','admin.php?page=wc-orders&status=wc-processing','edit.php?post_type=product','edit.php']) {
  assert.ok(canonical(path,base),path); checks++;
}
for (const path of ['https://evil.example/wp-admin/admin.php?page=pc-data','admin-post.php?action=delete','admin.php?page=pc-data&_wpnonce=x','admin.php?page=wc-orders&action=delete','admin.php?page=wc-orders&status=trash','admin.php?page=pc-data&page=pc-overview','post.php?post=1&action=edit','admin.php?page=pchq','admin.php?page=pc-data#x','edit.php?post_type=pc_batch','admin.php?page=pc-data&export=1','https://a:b@store.example/wp-admin/admin.php?page=pc-data']) {
  assert.equal(canonical(path,base),null,path); checks++;
}
assert.equal(canonical('admin.php?page=pc-data&pc_embed=1',base).search,'?page=pc-data'); checks++;
const form = {elements:[{name:'name',type:'text',value:'old'},{name:'_wpnonce',type:'hidden',value:'old'}]};
const before = signature(form); form.elements[1].value='rotated'; assert.equal(signature(form),before); checks++;
form.elements[0].value='changed'; assert.notEqual(signature(form),before); checks++;
console.log(JSON.stringify({checks,passed:true,browser:false}));
