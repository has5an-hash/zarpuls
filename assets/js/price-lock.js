(function($){
 'use strict';
 function pad(n){return String(Math.max(0,n)).padStart(2,'0');}
 function format(seconds){return pad(Math.floor(seconds/60))+':'+pad(seconds%60);}
 function tick(){
   var now=Math.floor(Date.now()/1000),expired=false;
   $('.zg-lock-item[data-zg-expiry]').each(function(){
     var item=$(this),expiry=parseInt(item.data('zg-expiry'),10),left=Math.max(0,expiry-now),total=(parseInt(item.closest('.zg-lock-timers').data('lock-minutes'),10)||5)*60,percent=Math.max(0,Math.min(100,left/total*100));
     item.find('.zg-lock-time').text(format(left)); item.find('.zg-lock-progress i').css('width',percent+'%');
     item.toggleClass('zg-lock-warning',left>0&&left<=60).toggleClass('zg-lock-expired',left===0);
     if(left===0){expired=true;}
   });
   if(expired&&!window.zgLockExpiredReload){window.zgLockExpiredReload=true;setTimeout(function(){window.location.reload();},1300);}
 }
 function modal(item){
   if(!item){return;}
   var expiry=parseInt(item.expires,10),left=Math.max(0,expiry-Math.floor(Date.now()/1000),0),name=$('<div>').text(item.name).html();
   var html='<div class="zg-lock-modal" role="dialog" aria-modal="true"><div class="zg-lock-modal-card"><div class="zg-lock-modal-mark">⌛</div><h3>'+ZGPriceLock.modalTitle+'</h3><p>'+ZGPriceLock.modalBody+'<br><strong>'+name+'</strong></p><div class="zg-lock-modal-time"><span>زمان باقی‌مانده</span><b data-zg-modal-expiry="'+expiry+'">'+format(left)+'</b></div><button class="zg-lock-modal-btn">'+ZGPriceLock.confirm+'</button></div></div>';
   $('body').append(html);var box=$('.zg-lock-modal').last();setTimeout(function(){box.addClass('is-open');},20);box.on('click','.zg-lock-modal-btn',function(){box.removeClass('is-open');setTimeout(function(){box.remove();},280);});
 }
 function status(){
   $.post(ZGPriceLock.ajaxUrl,{action:'zg_lock_status',nonce:ZGPriceLock.nonce}).done(function(res){
      if(!res||!res.success||!res.data.recent){return;}
      var found=null;$.each(res.data.items||[],function(_,item){if(item.key===res.data.recent){found=item;}});modal(found);
   });
 }
 $(function(){tick();setInterval(tick,1000);status();$(document.body).on('added_to_cart',function(){setTimeout(status,350);});});
})(jQuery);
