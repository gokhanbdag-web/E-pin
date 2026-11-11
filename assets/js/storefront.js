(function(){'use strict';
var b=document.querySelector('.storefront-menu-toggle'),n=document.getElementById('storefront-navigation');
if(b&&n){b.addEventListener('click',function(){var o=b.getAttribute('aria-expanded')!=='true';b.setAttribute('aria-expanded',String(o));b.setAttribute('aria-label',o?'Menüyü kapat':'Menüyü aç');n.classList.toggle('is-open',o);document.body.classList.toggle('storefront-menu-open',o);});n.addEventListener('click',function(e){if(e.target.closest('a')){b.setAttribute('aria-expanded','false');n.classList.remove('is-open');document.body.classList.remove('storefront-menu-open');}});}
})();
(function(){'use strict';
var root=document.querySelector('[data-game-slider]');if(!root)return;
var slides=Array.prototype.slice.call(root.querySelectorAll('[data-slide]')),dots=Array.prototype.slice.call(root.querySelectorAll('[data-go-to]')),prev=root.querySelector('[data-slider-prev]'),next=root.querySelector('[data-slider-next]'),bar=root.querySelector('.game-slider-progress span');
if(slides.length<2)return;
var index=0,delay=Math.max(3500,Math.min(15000,parseInt(root.getAttribute('data-interval')||'6500',10)||6500)),timer=null,started=Date.now(),paused=false;
var reduced=window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches;
function paint(){slides.forEach(function(s,i){var active=i===index;s.classList.toggle('is-active',active);s.setAttribute('aria-hidden',active?'false':'true');});dots.forEach(function(d,i){var active=i===index;d.classList.toggle('is-active',active);d.setAttribute('aria-current',active?'true':'false');});started=Date.now();if(bar){bar.style.transition='none';bar.style.width='0%';void bar.offsetWidth;bar.style.transition='width '+delay+'ms linear';if(!paused&&!reduced)bar.style.width='100%';}}
function go(i){index=(i+slides.length)%slides.length;paint();}
function stop(){if(timer){window.clearInterval(timer);timer=null;}}
function start(){stop();if(paused||reduced||root.getAttribute('data-autoplay')==='0')return;timer=window.setInterval(function(){go(index+1);},delay);}
if(prev)prev.addEventListener('click',function(){go(index-1);start();});
if(next)next.addEventListener('click',function(){go(index+1);start();});
dots.forEach(function(d){d.addEventListener('click',function(){go(parseInt(d.getAttribute('data-go-to'),10)||0);start();});});
root.addEventListener('mouseenter',function(){paused=true;stop();if(bar)bar.style.transition='none';});
root.addEventListener('mouseleave',function(){paused=false;paint();start();});
root.addEventListener('focusin',function(){paused=true;stop();});
root.addEventListener('focusout',function(e){if(!root.contains(e.relatedTarget)){paused=false;paint();start();}});
var touchStart=null;root.addEventListener('touchstart',function(e){touchStart=e.changedTouches[0].clientX;},{passive:true});
root.addEventListener('touchend',function(e){if(touchStart===null)return;var delta=e.changedTouches[0].clientX-touchStart;touchStart=null;if(Math.abs(delta)>45){go(index+(delta<0?1:-1));start();}},{passive:true});
document.addEventListener('visibilitychange',function(){if(document.hidden)stop();else start();});
paint();start();
})();