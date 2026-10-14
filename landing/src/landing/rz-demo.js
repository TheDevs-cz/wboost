/* Wboost landing — /klienti: the live "one poster, every format" mock-ups.
 * Ported verbatim from the design export (ui_kits/landing/rz-demo.js); changes:
 * waits for Montserrat before measuring text, opens via the wb:md-open event. */
(function(){
const G={blk:'#DCDEE6',blk2:'#C4C7D2',ink:'#3D4152',mid:'#6E7387',lt:'#9A9EAE'},RED='#E5484D',RT='rgba(229,72,77,.14)';
const PRE=[{t:'Odstávka vody',d:'Čt 12. 9. · 8:00–14:00',s:'Z důvodu opravy vodovodního řadu bude v ulicích Nádražní, Lesní a Krátká přerušena dodávka vody. Cisterna s pitnou vodou bude přistavena u obecního úřadu. Děkujeme za pochopení.'},
{t:'Dětský den s hasiči',d:'Ne 1. 6. · od 13:00',s:'Soutěže, skákací hrad, ukázka hasičské techniky a malování na obličej. Občerstvení zajištěno. Vstup zdarma pro děti i rodiče. Akce se koná na hřišti u hasičárny.'},
{t:'Rozsvícení vánočního stromu',d:'Ne 29. 11. · 17:00',s:'Přijďte s námi zahájit advent. Vystoupí děti ze základní školy, čeká vás svařák, punč a vánoční jarmark místních spolků. Na závěr malý ohňostroj.'}];
let S=Object.assign({},PRE[0]),pi=0,tt=null,touched=false;
const SZ={poster:[420,594],post:[540,675],postmd:[540,827],fbh:[540,140],story:[360,640],news:[592,420]};
const esc=t=>String(t).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const cx=document.createElement('canvas').getContext('2d');let FF=null;
function fit(t,w,h,max,min,lh){FF=FF||getComputedStyle(document.body).fontFamily;const ws=(t||' ').trim().split(/\s+/);
for(let s=max;s>=min;s-=2){cx.font='700 '+s+'px '+FF;let n=1,cur='',ok=true;
for(const x of ws){const tr=cur?cur+' '+x:x;if(cx.measureText(tr).width*1.03<=w)cur=tr;else{if(cx.measureText(x).width*1.03>w){ok=false;break}n++;cur=x}}
if(ok&&n*s*lh<=h)return s}return min}
const P=(x,y,w,h,st,inner)=>`<div style="position:absolute;left:${x}px;top:${y}px;width:${w}px;height:${h}px;${st}">${inner||''}</div>`;
const logo=(x,y,s,r)=>P(x,y,s,s,`border-radius:${s*.24}px;background:${r?RT:G.blk};${r?'box-shadow:0 0 0 2px '+RED+';':''}display:flex;align-items:center;justify-content:center;font-size:${s*.2}px;font-weight:700;letter-spacing:.06em;color:${r?RED:G.mid}`,'LOGO');
const img=(x,y,w,h)=>S.img?P(x,y,w,h,'border-radius:8px;background:url('+S.img+') center/cover'):P(x,y,w,h,`border-radius:8px;background:repeating-linear-gradient(135deg,#E6E8EE 0 8px,#EFF0F4 8px 16px);box-shadow:inset 0 0 0 1.5px ${G.blk};display:flex;align-items:center;justify-content:center;font-size:${Math.max(10,w*.03)}px;font-weight:700;letter-spacing:.12em;color:${G.lt}`,'OBRÁZEK');
const ttl=(x,y,w,h,s)=>P(x,y,w,h,`display:flex;align-items:flex-end;font-size:${s}px;font-weight:700;line-height:1.04;letter-spacing:-.01em;color:${G.ink};text-wrap:balance`,esc(S.t));
const date=(x,y,s)=>P(x,y,'auto','auto',`font-size:${s}px;font-weight:700;color:${G.ink};white-space:nowrap`,esc(S.d));
const pillD=(x,y,s)=>P(x,y,'auto','auto',`background:${G.ink};color:#fff;font-size:${s}px;font-weight:700;padding:${s*.3}px ${s*.6}px;border-radius:${s*.45}px;white-space:nowrap`,esc(S.d));
const desc=(x,y,w,h,s,r)=>P(x,y,w,h,`font-size:${s}px;line-height:1.5;color:${r?RED:G.mid};overflow:hidden`,esc(S.s));
const bar=(x,y,w,h,r,fs,px)=>P(x,y,w,h,`background:${r?RT:'#EEF0F4'};${r?'box-shadow:inset 0 0 0 1.5px '+RED+';':''}display:flex;align-items:center;gap:${h*.16}px;padding:0 ${px}px;box-sizing:border-box;font-size:${fs}px;color:${r?RED:G.mid}`,('<i style="width:'+h*.42+'px;height:'+h*.42+'px;border-radius:50%;background:'+(r?RED:G.blk2)+'"></i>').repeat(4)+'<span style="margin-left:auto;white-space:nowrap">www.obec-lhota.cz · 777 123 456</span>');
const qr=(x,y,s,r)=>P(x,y,s,s,'background:'+(r?RT:'#fff')+';box-shadow:inset 0 0 0 '+s*.09+'px '+(r?RED:G.ink)+';display:flex;align-items:center;justify-content:center','<span style="display:flex;align-items:center;justify-content:center;width:'+s*.5+'px;height:'+s*.5+'px;background:'+(r?RED:G.blk)+';color:'+(r?'#fff':G.ink)+';border-radius:2px;font-size:'+s*.2+'px;font-weight:800">QR</span>');
const poster=(r={})=>P(0,0,420,594,'background:#fff;overflow:hidden',logo(24,18,44,r.logo)+img(24,84,372,190)+ttl(24,288,372,78,fit(S.t,372,78,40,18,1.05))+date(24,376,17)+desc(24,408,282,94,10.5,r.desc)+qr(320,410,76,r.qr)+bar(0,530,420,64,r.bar,13,24));
const at=(h,s,x,y,ex)=>`<div style="position:absolute;left:${x}px;top:${y}px;width:420px;height:594px;transform:scale(${s});transform-origin:0 0;${ex||''}">${h}</div>`;
const bg=c=>`<div style="position:absolute;inset:0;background:${c}"></div>`;
const hatch=a=>`repeating-linear-gradient(135deg,rgba(229,72,77,${a}) 0 10px,rgba(229,72,77,${a*.7}) 10px 20px)`;
const edge=(y,h,t)=>P(0,y,'100%',h,`background:${hatch(.9)};display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;font-weight:700`,t);
const pill=(t,x,y,b,c)=>P(x,y,'auto','auto',`transform:translateX(-50%);background:${b};color:${c};font-size:13px;font-weight:700;padding:7px 12px;border-radius:999px;white-space:nowrap;box-shadow:0 6px 16px rgba(26,28,51,.25)`,t);
const sUI=()=>P(10,10,340,3,'display:flex;gap:4px','<i style="flex:1;border-radius:2px;background:'+G.blk2+'"></i>'.repeat(3))+P(14,24,332,34,'display:flex;align-items:center;gap:10px;color:'+G.mid+';font-size:14px;font-weight:700','<i style="width:34px;height:34px;border-radius:50%;background:'+G.blk2+'"></i>obec.lhota<span style="font-weight:400">2 h</span><span style="margin-left:auto;font-size:22px;font-weight:400">✕</span>')+P(14,578,332,44,'display:flex;align-items:center;gap:14px;color:'+G.mid,'<span style="flex:1;height:44px;border:1.5px solid '+G.blk2+';background:#fff;border-radius:22px;display:flex;align-items:center;padding:0 18px;font-size:14px">Odeslat zprávu</span><span style="font-size:24px">♡</span><span style="font-size:20px">➤</span>');
const LN=[1,.94,.98,.9,1,.96,.88,.97,1,.62];
const lines=(x,y,w,n,g)=>{let s='';for(let i=0;i<n;i++)s+=P(x,y+i*g,w*LN[i%LN.length],3,'background:#E3E5EB;border-radius:2px');return s};
const hd=(x,y,t,s)=>P(x,y,'auto','auto','font-size:'+s+'px;font-weight:700;color:'+G.ink+';white-space:nowrap',t);
const spread=()=>bg('#fff')+P(282,0,28,420,'background:linear-gradient(90deg,rgba(26,28,51,0),rgba(26,28,51,.08) 50%,rgba(26,28,51,0))')+P(295.5,0,1,420,'background:#E3E5EB')
+hd(20,18,'ZPRAVODAJ',18)+P(20,40,'auto','auto','font-size:8px;color:'+G.lt,'Obec Lhota · září 2026')+P(20,54,256,1.5,'background:'+G.blk)
+hd(20,66,'Slovo starosty',11)+lines(20,86,124,20,7)+lines(152,86,124,20,7)+img(20,234,256,84)+lines(20,328,124,9,7)+lines(152,328,124,9,7)
+hd(316,18,'Z obce',11)+lines(316,38,122,19,7)+lines(450,38,122,19,7)+hd(316,178,'Pozvánky',11)+lines(316,198,120,28,7)
+P(20,404,'auto','auto','font-size:7px;color:'+G.lt,'2')+P(566,404,'auto','auto','font-size:7px;color:'+G.lt,'3');
const A6=(x,y,s)=>P(x,y,296,418,'transform:scale('+s+');transform-origin:0 0;background:#fff;box-shadow:0 0 0 1.5px '+G.blk+';overflow:hidden',img(16,16,264,150)+ttl(16,178,264,108,fit(S.t,264,108,40,20,1.04))+date(16,296,17)+desc(16,326,180,44,11)+qr(208,296,72)+bar(0,378,296,40,0,10,12));
const fbHead=ok=>{const t=ok?(S.s.length>105?S.s.slice(0,105).replace(/\s+\S*$/,'')+'…':S.s):'Zveme vás! Všechny informace najdete na plakátu.';return P(16,16,44,44,'border-radius:50%;background:'+G.blk+';display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;color:'+G.mid,'LOGO')+P(70,18,'auto','auto','font-size:15px;font-weight:700;color:'+G.ink,'Obec Lhota')+P(70,40,'auto','auto','font-size:12px;color:'+G.lt,'2 h · Veřejné')+P(500,14,'auto','auto','font-size:22px;color:'+G.lt,'⋯')+P(16,74,508,52,'font-size:14px;line-height:1.5;color:'+G.ink+';overflow:hidden',esc(t)+(ok?' <b style="color:'+G.mid+'">Zobrazit více</b>':''))};
const fb=(ok,inner)=>inner;
const fbm=(ok,inner)=>fbHead(ok)+P(0,152,540,675,'overflow:hidden;border-radius:6px;box-shadow:0 0 0 1.5px '+G.blk2,inner);
const R={
poster:()=>poster(),
'post-bad':()=>fb(0,bg('#fff')+at(poster({logo:1,bar:1,desc:1,qr:1}),675/514,-5.8,-52.5)+P(0,0,540,4,'background:'+RED)+P(0,671,540,4,'background:'+RED)),
'story-bad':()=>bg('#F4F5F8')+bg(hatch(.12))+at(poster({desc:1,logo:1,qr:1}),.7,33,30,'box-shadow:0 0 0 2px '+RED)+sUI(),
'news-bad':()=>spread()+P(447,226,138,187,'background:'+RT+';border-radius:6px')+at(poster({desc:1,bar:1}).split(G.ink).join(RED),.28,457,236,'box-shadow:0 0 0 2px '+RED),
'post-ok':()=>fb(1,bg('#fff')+img(36,36,468,356)+ttl(36,408,468,144,fit(S.t,468,144,72,30,1.04))+pillD(36,572,26)),
'story-ok':()=>bg('#fff')+img(0,0,360,350)+ttl(28,362,304,104,fit(S.t,304,104,54,24,1.04))+pillD(28,474,22)+sUI(),
'news-ok':()=>spread()+A6(427,194,.5)};
const zone=(y,h,b)=>P(0,y,360,h,`background:repeating-linear-gradient(135deg,rgba(61,65,82,.12) 0 8px,rgba(61,65,82,.04) 8px 16px);border-${b}:2px dashed ${G.mid};box-sizing:border-box`);
Object.assign(R,{
'post-bad-md':()=>fb(0,bg('#EEEFF5')+at(poster({logo:1,bar:1,desc:1,qr:1}),675/594,31.4,0)+P(31.4,0,477.3,45.4,`background:${hatch(.45)}`)+P(31.4,629.6,477.3,45.4,`background:${hatch(.45)}`)+P(31.4,45.4,477.3,584.2,`border:3px dashed ${G.ink};box-sizing:border-box`)+pill('✂ Oříznuto',400,8,RED,'#fff')+pill('✂ Oříznuto',400,636,RED,'#fff')),
'story-bad-md':()=>R['story-bad']()+pill('Hluché místo',16.5,240,RED,'#fff').replace('translateX(-50%)','translateX(-50%) rotate(-90deg)')+pill('Hluché místo',343.5,240,RED,'#fff').replace('translateX(-50%)','translateX(-50%) rotate(90deg)')+pill('↑ Lišta překrývá logo',180,92,RED,'#fff')+pill('🔍 Nejde přiblížit',180,500,RED,'#fff'),
'news-bad-md':()=>R['news-bad']()+P(318,40,190,190,`border-radius:50%;overflow:hidden;border:4px solid #fff;box-shadow:0 10px 30px rgba(26,28,51,.3);background:#fff`,P(95-516*3,95-363*3,592,420,'transform:scale(3);transform-origin:0 0;filter:blur(1px)',R['news-bad']()))+pill('Popis v tisku ≈ 1 mm',413,240,RED,'#fff'),
'post-ok-md':()=>fbm(1,R['post-ok']()),'fbh-bad':()=>fbHead(0),'fbh-ok':()=>fbHead(1),
'story-ok-md':()=>R['story-ok']()+zone(0,100,'bottom')+zone(520,120,'top')+pill('Volné místo pro ovládání',180,34,G.ink,'#fff')+pill('Volné místo pro ovládání',180,566,G.ink,'#fff'),
'news-ok-md':()=>R['news-ok']()});
const sc=h=>{const c=h.firstChild;if(c&&h.clientWidth)c.style.transform='scale('+h.clientWidth/h._w+')'};
const ro=new ResizeObserver(es=>es.forEach(e=>sc(e.target)));
const hosts=[...document.querySelectorAll('.rz-cv')];hosts.forEach(h=>ro.observe(h));
function render(){hosts.forEach(h=>{const k=h.dataset.r,f=R[k];if(!f)return;const z=SZ[k==='post-ok-md'?'postmd':k.split('-')[0]];h._w=z[0];h.innerHTML=`<div style="width:${z[0]}px;height:${z[1]}px">${f()}</div>`;sc(h)})}
const ed=document.querySelector('.rz-ed');
function sync(){ed.querySelectorAll('input[data-k]').forEach(i=>{if(document.activeElement!==i)i.value=S[i.dataset.k]})}
function play(p){clearInterval(tt);S.d=p.d;S.s=p.s;let i=0;tt=setInterval(()=>{i++;S.t=p.t.slice(0,i);sync();render();if(i>=p.t.length)clearInterval(tt)},40)}
const next=()=>{pi=(pi+1)%PRE.length;play(PRE[pi])};
const stop=()=>{touched=true;clearInterval(tt)};
ed.querySelectorAll('input[data-k]').forEach(i=>i.addEventListener('input',()=>{stop();S[i.dataset.k]=i.value;render()}));
ed.querySelector('[data-up]').addEventListener('change',e=>{const f=e.target.files[0];if(!f)return;stop();if(S.img)URL.revokeObjectURL(S.img);S.img=URL.createObjectURL(f);render()});
let vis=false;new IntersectionObserver(es=>{vis=es[0].isIntersecting},{threshold:.35}).observe(document.querySelector('.rz'));
document.addEventListener('wb:md-open',e=>{if(e.detail!=='md-try')return;clearInterval(tt);S.t=PRE[pi].t;sync();render();setTimeout(()=>ed.querySelector('input[data-k]').focus(),100)});
setInterval(()=>{if(vis&&!touched&&!document.querySelector('.md:not([hidden])'))next()},6500);
sync();render();
(document.fonts?document.fonts.ready:Promise.resolve()).then(()=>{FF=null;render()});
})();
