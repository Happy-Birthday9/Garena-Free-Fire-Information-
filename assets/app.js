const $=s=>document.querySelector(s);
const LS={settings:"ff_uid_settings_v1",history:"ff_uid_history_v1",favorites:"ff_uid_favorites_v1"};
const defaults={apiUrl:"https://api.gameskinbo.com/ff-info/get",apiKey:"",paramName:"uid"};
let settings=load(LS.settings,defaults), current=null;
function load(k,d){try{return JSON.parse(localStorage.getItem(k))??d}catch{return d}}
function save(k,v){localStorage.setItem(k,JSON.stringify(v))}
function esc(v){return String(v??"N/A").replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#039;"}[c]))}
function label(k){return String(k).replace(/([a-z])([A-Z])/g,"$1 $2").replace(/[_-]/g," ").replace(/^./,x=>x.toUpperCase())}
function validUID(uid){return /^\d{10,12}$/.test(uid)}
function setStatus(t,good=false){$("#status").textContent=t;$("#status").style.color=good?"#8ee7ad":"#aeb8d3"}
function switchTab(tab){document.querySelectorAll(".tab").forEach(b=>b.classList.toggle("active",b.dataset.tab===tab));document.querySelectorAll(".tabPanel").forEach(p=>p.classList.add("hidden"));$(`#${tab}Tab`).classList.remove("hidden");if(tab==="favorites")renderFavorites();if(tab==="history")renderHistory();if(tab==="stats")renderStats()}
async function search(uid,addHistory=true){
 uid=String(uid).trim(); if(!validUID(uid)){setStatus("❌ UID must contain 10–12 digits.");return}
 setStatus("🔄 Searching…");$("#resultEmpty").classList.add("hidden");$("#result").classList.remove("hidden");
 try{
   const url=new URL(settings.apiUrl); url.searchParams.set(settings.paramName||"uid",uid);
   const headers={"Accept":"application/json"}; if(settings.apiKey)headers["x-api-key"]=settings.apiKey;
   const res=await fetch(url,{headers}); if(!res.ok)throw new Error(`HTTP ${res.status}`);
   const data=await res.json();
   if(data.error)throw new Error(data.error);
   current={uid,data}; renderPlayer();
   if(addHistory){let h=load(LS.history,[]);h=h.filter(x=>x.uid!==uid);h.unshift({uid,name:getName(data),time:new Date().toISOString(),data});save(LS.history,h.slice(0,50));}
   setStatus("✅ Player information loaded.",true);
 }catch(e){setStatus("❌ Search failed: "+e.message);$("#result").innerHTML=`<div class="card empty"><div class="emptyIcon">⚠️</div><h2>Could not load player</h2><p>API URL, CORS, API key অথবা UID check করুন।</p></div>`}
}
function getName(d){return d?.AccountInfo?.AccountName||d?.account?.name||"Unknown Player"}
function renderPlayer(){
 const d=current.data, uid=current.uid, fav=load(LS.favorites,[]).some(x=>x.uid===uid);
 const frag=$("#playerTemplate").content.cloneNode(true); const root=frag.querySelector(".player");
 root.querySelector("#playerName").textContent=getName(d);root.querySelector("#playerUid").textContent="UID: "+uid;
 root.querySelector("#favorite").textContent=fav?"⭐ Remove Favorite":"⭐ Add Favorite";
 root.querySelector("#refresh").onclick=()=>search(uid,false);
 root.querySelector("#favorite").onclick=()=>toggleFavorite();
 root.querySelector("#copyJson").onclick=()=>navigator.clipboard?.writeText(JSON.stringify(d,null,2));
 root.querySelector("#rawJson").textContent=JSON.stringify(d,null,2);
 const sections=root.querySelector("#sections");
 const preferred=["AccountInfo","AccountProfileInfo","CreditScoreInfo","SocialInfo","GuildInfo","GuildOwnerInfo","PetInfo","EquippedItemsInfo"];
 const keys=[...preferred.filter(k=>d&&typeof d[k]==="object"),...Object.keys(d||{}).filter(k=>!preferred.includes(k)&&d[k]&&typeof d[k]==="object")];
 keys.forEach(k=>{const obj=d[k];const sec=document.createElement("section");sec.className="dataSection";sec.innerHTML=`<h3>📌 ${esc(label(k))}</h3><div class="rows"></div>`;const rows=sec.querySelector(".rows");Object.entries(obj).forEach(([a,v])=>{if(v&&typeof v==="object")v=JSON.stringify(v);const r=document.createElement("div");r.className="row";r.innerHTML=`<label>${esc(label(a))}</label><span>${esc(v)}</span>`;rows.appendChild(r)});sections.appendChild(sec)});
 $("#result").replaceChildren(frag);
}
function toggleFavorite(){if(!current)return;let f=load(LS.favorites,[]);const i=f.findIndex(x=>x.uid===current.uid);if(i>=0)f.splice(i,1);else f.unshift({uid:current.uid,name:getName(current.data),time:new Date().toISOString(),data:current.data});save(LS.favorites,f);renderPlayer();updateCounts();renderFavorites()}
function renderFavorites(){const box=$("#favoritesList"),f=load(LS.favorites,[]);box.innerHTML=f.length?f.map(x=>`<div class="listItem"><div><b>${esc(x.name)}</b><small>UID: ${esc(x.uid)} · ${new Date(x.time).toLocaleString()}</small></div><div class="listActions"><button class="secondary" onclick="search('${esc(x.uid)}')">View</button><button class="dangerBtn" onclick="removeFav('${esc(x.uid)}')">Remove</button></div></div>`).join(""):`<div class="empty card"><div class="emptyIcon">⭐</div><p>No favorites yet.</p></div>`}
function removeFav(uid){save(LS.favorites,load(LS.favorites,[]).filter(x=>x.uid!==uid));renderFavorites();updateCounts();if(current?.uid===uid)renderPlayer()}
function renderHistory(){const box=$("#historyList"),h=load(LS.history,[]);box.innerHTML=h.length?h.map(x=>`<div class="listItem"><div><b>${esc(x.name)}</b><small>UID: ${esc(x.uid)} · ${new Date(x.time).toLocaleString()}</small></div><button class="secondary" onclick="search('${esc(x.uid)}')">View</button></div>`).join(""):`<div class="empty card"><div class="emptyIcon">📋</div><p>No search history yet.</p></div>`}
function renderStats(){const h=load(LS.history,[]),f=load(LS.favorites,[]);$("#statsGrid").innerHTML=`<div class="stat card"><div class="n">${h.length}</div><small>Recent searches stored</small></div><div class="stat card"><div class="n">${f.length}</div><small>Favorites</small></div><div class="stat card"><div class="n">${new Set(h.map(x=>x.uid)).size}</div><small>Unique UIDs</small></div>`}
function updateCounts(){$("#favCount").textContent=`(${load(LS.favorites,[]).length})`}
$("#searchBtn").onclick=()=>search($("#uidInput").value);
$("#uidInput").addEventListener("keydown",e=>{if(e.key==="Enter")search(e.target.value)});
document.querySelectorAll(".tab").forEach(b=>b.onclick=()=>switchTab(b.dataset.tab));
$("#clearFav").onclick=()=>{if(confirm("Clear all favorites?")){save(LS.favorites,[]);renderFavorites();updateCounts()}};
$("#clearHistory").onclick=()=>{if(confirm("Clear all history?")){save(LS.history,[]);renderHistory();renderStats()}};
$("#settingsBtn").onclick=()=>{$("#apiUrl").value=settings.apiUrl;$("#apiKey").value=settings.apiKey;$("#paramName").value=settings.paramName;$("#settings").showModal()};
$("#saveSettings").onclick=()=>{settings={apiUrl:$("#apiUrl").value.trim(),apiKey:$("#apiKey").value.trim(),paramName:$("#paramName").value.trim()||"uid"};save(LS.settings,settings);setStatus("✅ Settings saved.",true)};
updateCounts();renderStats();
