(function(){'use strict';
var b=document.querySelector('.admin-menu-toggle'),n=document.getElementById('admin-navigation'),o=document.querySelector('[data-admin-close]');if(!b||!n)return;
function setMenuOpen(open,restoreFocus){
 b.setAttribute('aria-expanded',String(open));
 b.setAttribute('aria-label',open?'Yönetim menüsünü kapat':'Yönetim menüsünü aç');
 n.classList.toggle('is-open',open);
 if(o)o.classList.toggle('is-visible',open);
 document.body.classList.toggle('admin-menu-open',open);
 if(open){var first=n.querySelector('a[href],button:not([disabled])');if(first)first.focus();}
 if(!open&&restoreFocus)b.focus();
}
b.addEventListener('click',function(){setMenuOpen(b.getAttribute('aria-expanded')!=='true',false);});
if(o)o.addEventListener('click',function(){setMenuOpen(false,true);});
n.addEventListener('click',function(e){if(e.target.closest('a'))setMenuOpen(false,false);});
document.addEventListener('keydown',function(e){
 if(b.getAttribute('aria-expanded')!=='true')return;
 if(e.key==='Escape'){setMenuOpen(false,true);return;}
 if(e.key!=='Tab')return;
 var items=Array.prototype.slice.call(n.querySelectorAll('a[href],button:not([disabled]),[tabindex]:not([tabindex="-1"])')).filter(function(el){return el.getClientRects().length>0;});
 if(!items.length){e.preventDefault();b.focus();return;}
 var first=items[0],last=items[items.length-1];
 if(e.shiftKey&&(document.activeElement===first||!n.contains(document.activeElement))){e.preventDefault();last.focus();}
 else if(!e.shiftKey&&(document.activeElement===last||!n.contains(document.activeElement))){e.preventDefault();first.focus();}
});
window.addEventListener('resize',function(){if(window.innerWidth>760&&b.getAttribute('aria-expanded')==='true')setMenuOpen(false,false);});
})();
(function(){'use strict';
var form=document.querySelector('.slider-manager-form');if(!form)return;
var list=form,dragged=null;
function cards(){return Array.prototype.slice.call(list.querySelectorAll('.slider-manager-card'));}
function renumber(){cards().forEach(function(card,i){var input=card.querySelector('[data-sort-order]');if(input)input.value=String(i+1);var heading=card.querySelector('.slider-card-title h2');if(heading)heading.textContent='Slayt '+(i+1);var up=card.querySelector('[data-move-up]'),down=card.querySelector('[data-move-down]');if(up)up.disabled=i===0;if(down)down.disabled=i===cards().length-1;});}
function move(card,target,after){if(!card||!target||card===target)return;list.insertBefore(card,after?target.nextSibling:target);renumber();}
cards().forEach(function(card){
var handle=card.querySelector('.slider-drag-handle');if(!handle)return;
handle.addEventListener('pointerdown',function(e){if(e.button!==undefined&&e.button!==0)return;dragged=card;card.classList.add('is-dragging');try{handle.setPointerCapture(e.pointerId);}catch(_){}e.preventDefault();});
handle.addEventListener('pointermove',function(e){if(!dragged)return;var under=document.elementFromPoint(e.clientX,e.clientY),target=under&&under.closest('.slider-manager-card');cards().forEach(function(c){c.classList.toggle('is-drop-target',c===target&&c!==dragged);});if(!target||target===dragged)return;var rect=target.getBoundingClientRect();move(dragged,target,e.clientY>rect.top+rect.height/2);});
function end(){if(!dragged)return;dragged.classList.remove('is-dragging');cards().forEach(function(c){c.classList.remove('is-drop-target');});dragged=null;renumber();}
handle.addEventListener('pointerup',end);handle.addEventListener('pointercancel',end);
});
list.addEventListener('click',function(e){var up=e.target.closest('[data-move-up]'),down=e.target.closest('[data-move-down]');if(up){var c=up.closest('.slider-manager-card'),p=c&&c.previousElementSibling;if(p&&p.classList.contains('slider-manager-card'))list.insertBefore(c,p);renumber();}if(down){var d=down.closest('.slider-manager-card'),n=d&&d.nextElementSibling;if(n&&n.classList.contains('slider-manager-card'))list.insertBefore(n,d);renumber();}});
renumber();
})();

(function(){'use strict';
document.addEventListener('submit',function(e){
 var form=e.target;
 if(form&&form.matches&&form.matches('.slider-manager-form')&&form.querySelector('input[name$="[delete]"]:checked')){
  if(!window.confirm('Seçtiğiniz slaytlar kalıcı olarak silinecek. Devam etmek istiyor musunuz?'))e.preventDefault();
 }
});
})();


(function(){'use strict';
document.addEventListener('click',function(e){
 var close=e.target.closest('.admin-content .alert-dismiss');
 if(!close)return;
 var alert=close.closest('.alert');
 if(alert){alert.setAttribute('aria-hidden','true');alert.remove();}
});
})();
