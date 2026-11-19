(function () {
'use strict';
let active, popup;
function close() { if(active)active.querySelector('button').setAttribute('aria-expanded','false'); if(popup)popup.remove(); active=popup=null; }
function position() { if(!active||!popup)return; const a=active.getBoundingClientRect(),b=popup.getBoundingClientRect(); popup.style.left=Math.max(12,Math.min(a.left,innerWidth-b.width-12))+'px'; popup.style.top=Math.max(12,Math.min(a.bottom+8,innerHeight-b.height-12))+'px'; }
function open(help) { if(active===help)return; close();active=help;popup=document.createElement('div');popup.className='anar-help-popover';popup.setAttribute('role','tooltip');popup.textContent=help.querySelector('[role="tooltip"]').textContent;popup.dir=help.closest('[dir]')?.dir||document.documentElement.dir||'ltr';const topLayer=typeof popup.showPopover==='function';if(topLayer)popup.setAttribute('popover','manual');(topLayer?document.body:(help.closest('dialog[open]')||document.body)).append(popup);if(topLayer)popup.showPopover();help.querySelector('button').setAttribute('aria-expanded','true');position(); }
document.addEventListener('mouseover',e=>{const h=e.target.closest('.anar-help');if(h)open(h);});
document.addEventListener('focusin',e=>{const h=e.target.closest('.anar-help');if(h)open(h);else if(!popup?.contains(e.target))close();});
document.addEventListener('click',e=>{const h=e.target.closest('.anar-help');if(h)open(h);else if(!popup?.contains(e.target))close();});
document.addEventListener('mouseout',e=>{if(active?.contains(e.target)&&!active.contains(e.relatedTarget)&&!popup?.contains(e.relatedTarget)&&!active.matches(':focus-within'))close();});
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&active){e.preventDefault();e.stopPropagation();close();}},true);
window.addEventListener('scroll',position,true);window.addEventListener('resize',position);
})();
