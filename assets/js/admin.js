(function(){'use strict';var b=document.querySelector('.admin-menu-toggle'),n=document.getElementById('admin-navigation'),o=document.querySelector('[data-admin-close]');if(!b||!n)return;function close(){b.setAttribute('aria-expanded','false');n.classList.remove('is-open');if(o)o.classList.remove('is-visible');document.body.classList.remove('admin-menu-open');}b.addEventListener('click',function(){var open=b.getAttribute('aria-expanded')!=='true';b.setAttribute('aria-expanded',String(open));n.classList.toggle('is-open',open);if(o)o.classList.toggle('is-visible',open);document.body.classList.toggle('admin-menu-open',open);});if(o)o.addEventListener('click',close);n.addEventListener('click',function(e){if(e.target.closest('a'))close();});document.addEventListener('keydown',function(e){if(e.key==='Escape')close();});})();
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
