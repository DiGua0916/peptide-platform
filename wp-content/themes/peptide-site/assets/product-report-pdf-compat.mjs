/* The pinned legacy PDF.js includes Promise.try, but assumes withResolvers. */
if (typeof Promise.withResolvers !== 'function') {
  Object.defineProperty(Promise,'withResolvers',{
    configurable:true,writable:true,
    value:function withResolvers() {
      let resolve,reject;
      const promise = new this((a,b) => {resolve = a; reject = b;});
      return {promise,resolve,reject};
    }
  });
}
