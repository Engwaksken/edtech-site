window.NL = (() => {
  'use strict';

  const PROCESS_URL = window.NL_PROCESS_URL || window.location.pathname;

  let blocks = [];
  let selectedId = null;
  let uploadedEmailsNL = [];
  let uploadedEmailsPDF = [];
  let draggedBlockId = null;
  let dropMarker = null;
  let activeSubmitter = null;
  let pendingDuplicate = null;
  let pendingDeleteForm = null;

  const fonts = [
    ['Figtree, Arial, sans-serif','Figtree'],
    ['Montserrat, Arial, sans-serif','Montserrat'],
    ['Inter, Arial, sans-serif','Inter'],
    ['Arial, sans-serif','Arial'],
    ['Georgia, serif','Georgia'],
    ['Fraunces, Georgia, serif','Fraunces'],
    ['Playfair Display, Georgia, serif','Playfair Display'],
    ['Times New Roman, serif','Times New Roman']
  ];

  const $ = id => document.getElementById(id);

  function esc(v){
    return String(v ?? '').replace(/[&<>"']/g,m=>({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
    })[m]);
  }

  function decode(v){
    const t=document.createElement('textarea');
    t.innerHTML=String(v??'');
    return t.value;
  }

  function text(v){
    return decode(v)
      .replace(/\uFFFD/g,"'")
      .replace(/[‘’]/g,"'")
      .replace(/[“”]/g,'"');
  }

  function uid(){
    return 'b_'+Date.now()+'_'+Math.random().toString(36).slice(2,8);
  }

  function clone(v){
    try{return JSON.parse(JSON.stringify(v||{}));}
    catch(e){return Object.assign({},v||{});}
  }

  function closeModal(id){
    $(id)?.classList.remove('open');
  }

  function baseStyle(){
    return {
      fontFamily:'Montserrat, Arial, sans-serif',
      fontSize:15,
      color:'#3f3f46',
      backgroundColor:'transparent',
      backgroundImage:'',
      paddingTop:10,
      paddingRight:0,
      paddingBottom:10,
      paddingLeft:0,
      align:'left'
    };
  }

  function defaultData(type){
    const c=baseStyle();

    const map={
      logo:{
        type:'logo',src:'',text:'Hive Colab',alt:'Logo',width:190,align:'center',
        ...c,paddingTop:32,paddingBottom:14
      },
      topbar:{
        type:'topbar',
        left:'Newsletter',
        right:'May 2026 - Issue 01',
        leftBg:'#f4512c',
        rightBg:'#ff9515',
        color:'#ffffff',
        fontFamily:'Montserrat, Arial, sans-serif',
        fontSize:18,
        fontWeight:'900',
        leftAlign:'left',
        rightAlign:'right',
        backgroundType:'none',
        backgroundColor:'transparent',
        backgroundImage:'',
        backgroundPosition:'center center',
        backgroundSize:'cover',
        backgroundRepeat:'no-repeat',
        paddingTop:14,
        paddingRight:28,
        paddingBottom:14,
        paddingLeft:28
      },
      heading:{
        type:'heading',text:'It’s been a season to remember, and we are just getting started.',
        level:'h1',fontFamily:'Montserrat, Arial, sans-serif',fontSize:31,
        color:'#f4512c',backgroundColor:'transparent',backgroundImage:'',
        align:'left',fontStyle:'normal',fontWeight:'800',
        paddingTop:34,paddingRight:44,paddingBottom:8,paddingLeft:44
      },
      text:{
        type:'text',content:'Write your message here. Make it clear and engaging.',
        fontFamily:'Montserrat, Arial, sans-serif',fontSize:15,color:'#3f3f46',
        backgroundColor:'transparent',backgroundImage:'',align:'left',
        lineHeight:1.55,paddingTop:10,paddingRight:44,paddingBottom:10,paddingLeft:44
      },
      image:{
        type:'image',src:'',alt:'',link:'',width:100,radius:0,align:'center',
        backgroundColor:'transparent',backgroundImage:'',
        paddingTop:12,paddingRight:44,paddingBottom:12,paddingLeft:44
      },
      button:{
        type:'button',text:'READ MORE ON OUR WEBSITE',url:'#',
        bgcolor:'#f4512c',color:'#fff',fontFamily:'Montserrat, Arial, sans-serif',
        fontSize:13,align:'left',radius:0,width:210,
        paddingTop:10,paddingRight:44,paddingBottom:10,paddingLeft:44,
        backgroundColor:'transparent',backgroundImage:''
      },
      divider:{
        type:'divider',style:'solid',color:'#f7941d',thickness:2,
        backgroundColor:'transparent',paddingTop:18,paddingRight:44,
        paddingBottom:18,paddingLeft:44
      },
      spacer:{type:'spacer',height:28,backgroundColor:'transparent'},
      section:{
        type:'section',
        title:'The Mastercard Foundation EdTech Fellowship Uganda is officially here',
        meta:'by Hive Colab - May 2026',
        body:'Add story details here.',
        img:'',buttonText:'READ MORE ON OUR WEBSITE',buttonUrl:'#',
        accent:'#f4512c',columns:'image-left',titleAlign:'left',
        fontFamily:'Montserrat, Arial, sans-serif',fontSize:15,color:'#3f3f46',
        titleColor:'#333',
        backgroundType:'color',
        backgroundColor:'#fff7f4',
        backgroundImage:'',
        backgroundPosition:'center center',
        backgroundSize:'cover',
        backgroundRepeat:'no-repeat',
        paddingTop:22,paddingRight:44,paddingBottom:22,paddingLeft:44
      },
      cta:{
        type:'cta',title:'Coming up at Hive Colab',
        body:'There is a lot in motion. Subscribe to be part of our updates.',
        highlight:'Keep an eye on this space.',signature:'The Beehive',
        accent:'#ff9515',fontFamily:'Montserrat, Arial, sans-serif',
        fontSize:19,titleSize:28,color:'#444',titleColor:'#333',
        highlightColor:'#f4512c',backgroundColor:'#fff',
        paddingTop:34,paddingRight:44,paddingBottom:34,paddingLeft:44,align:'left'
      },
      logoFooter:{
        type:'logoFooter',logo:'',logoText:'Hive Colab',tagline:'Abuzz with innovation',
        address:'Kanjokya House, 4th Floor, Kampala, Uganda',
        socials:'Follow us on X, LinkedIn, Instagram and YouTube',
        website:'www.hivecolab.org',websiteUrl:'https://www.hivecolab.org',
        backgroundColor:'#3a3a3d',color:'#f7941d',mutedColor:'#bdbdbd',
        logoWidth:330,layout:'logo-left',fontFamily:'Montserrat, Arial, sans-serif',
        fontSize:16,paddingTop:48,paddingRight:44,paddingBottom:48,paddingLeft:44
      },
      columns:{
        type:'columns',
        col1:{content:'Left column content'},
        col2:{content:'Right column content'},
        gap:18,cols:'1fr 1fr',fontFamily:'Montserrat, Arial, sans-serif',
        fontSize:15,color:'#3f3f46',backgroundColor:'transparent',
        paddingTop:14,paddingRight:44,paddingBottom:14,paddingLeft:44
      }
    };
    return map[type] || {type,...c};
  }

  function openBuilder(){
    resetBuilder();
    if($('nlModalTitle')) $('nlModalTitle').innerHTML='<i class="fa fa-magic"></i> Design Newsletter';
    $('nlModal')?.classList.add('open');
  }

  function closeBuilder(){ closeModal('nlModal'); }
  function openPdfModal(){ $('pdfModal')?.classList.add('open'); }
  function closePdfModal(){ closeModal('pdfModal'); }
  function openSubsModal(){ $('subsModal')?.classList.add('open'); }

  function resetBuilder(){
    blocks=[];
    selectedId=null;
    ['nl_id','nl_duplicated_from','nl_body_json','nl_body_html','nl_recipient_emails'].forEach(id=>{
      if($(id)) $(id).value='';
    });
    if($('nl_subject')) $('nl_subject').value='';
    if($('nl_preheader')) $('nl_preheader').value='';
    if($('nlHiddenInputs')) $('nlHiddenInputs').innerHTML='';
    if($('nlSaveStatus')) {
      $('nlSaveStatus').textContent='';
      $('nlSaveStatus').classList.remove('is-error');
    }
    renderCanvas();
    settingsEmpty();
  }

  function loadHiveTemplate(){
    blocks=[
      {id:uid(),...defaultData('logo')},
      {id:uid(),...defaultData('topbar')},
      {id:uid(),...defaultData('heading')},
      {id:uid(),...defaultData('text'),content:'Welcome to the latest Hive Colab newsletter.'},
      {id:uid(),...defaultData('divider')},
      {id:uid(),...defaultData('section')},
      {id:uid(),...defaultData('cta')},
      {id:uid(),...defaultData('logoFooter')}
    ];
    renderCanvas();
    if(blocks[0]) selectBlock(blocks[0].id);
  }

  function clearCanvas(){
    blocks=[];
    selectedId=null;
    renderCanvas();
    settingsEmpty();
  }

  function paletteClick(event,type,button){
    if(button?.dataset?.justDragged==='1'){
      event.preventDefault();
      return;
    }
    addBlock(type);
  }

  function addContentLayout(layout){
    const seq={
      'image-above':['image','heading','text'],
      'text-above':['heading','text','image']
    }[layout] || [];
    seq.forEach(t=>addBlock(t));
  }

  function addBlock(type,data={}){
    const b={id:uid(),...defaultData(type),...data};
    blocks.push(b);
    renderCanvas();
    selectBlock(b.id);
  }

  function blockStyle(b){
    const s=[];

    const bgType=b.backgroundType || (
      b.backgroundImage ? 'image' :
      (b.backgroundColor && b.backgroundColor!=='transparent' ? 'color' : 'none')
    );

    if(bgType==='color' && b.backgroundColor && b.backgroundColor!=='transparent'){
      s.push(`background-color:${b.backgroundColor}`);
    }

    if(bgType==='image' && b.backgroundImage){
      s.push(`background-image:url("${esc(b.backgroundImage)}")`);
      s.push(`background-size:${b.backgroundSize||'cover'}`);
      s.push(`background-position:${b.backgroundPosition||'center center'}`);
      s.push(`background-repeat:${b.backgroundRepeat||'no-repeat'}`);
    }

    if(bgType==='none'){
      s.push('background-color:transparent');
      s.push('background-image:none');
    }

    ['Top','Right','Bottom','Left'].forEach(side=>{
      const key='padding'+side;
      if(b[key]!==undefined) s.push(`padding-${side.toLowerCase()}:${Number(b[key])||0}px`);
    });

    return s.join(';');
  }

  /*
   * Shared background resolution, used by blockStyle() (for the
   * outer .nl-block div) and also applied directly to <table>
   * elements for blocks that render as tables (section's two-column
   * layout, the columns block, logoFooter).
   *
   * Outlook desktop (Word rendering engine) frequently fails to let
   * a background set on a parent <div> show through a nested
   * <table> - the background needs to live on the table/td itself
   * to reliably display there. Painting it in both places is safe:
   * both reads resolve to the exact same value from the same block
   * data, so there's no risk of the two drifting out of sync (unlike
   * the earlier section-background bug, which was two independently
   * computed values).
   */
  function resolvedBgType(b){
    return b.backgroundType || (
      b.backgroundImage ? 'image' :
      (b.backgroundColor && b.backgroundColor!=='transparent' ? 'color' : 'none')
    );
  }

  function backgroundOnlyStyle(b){
    const s=[];
    const bgType=resolvedBgType(b);

    if(bgType==='color' && b.backgroundColor && b.backgroundColor!=='transparent'){
      s.push(`background-color:${b.backgroundColor}`);
    }

    if(bgType==='image' && b.backgroundImage){
      s.push(`background-image:url("${esc(b.backgroundImage)}")`);
      s.push(`background-size:${b.backgroundSize||'cover'}`);
      s.push(`background-position:${b.backgroundPosition||'center center'}`);
      s.push(`background-repeat:${b.backgroundRepeat||'no-repeat'}`);
    }

    return s.join(';');
  }

  function backgroundColorAttr(b){
    const bgType=resolvedBgType(b);

    return (
      bgType==='color'
      && b.backgroundColor
      && b.backgroundColor!=='transparent'
    )
      ? ` bgcolor="${esc(b.backgroundColor)}"`
      : '';
  }

  function textStyle(b){
    const s=[];
    if(b.fontFamily) s.push(`font-family:${b.fontFamily}`);
    if(b.fontSize) s.push(`font-size:${Number(b.fontSize)||15}px`);
    if(b.color) s.push(`color:${b.color}`);
    if(b.align) s.push(`text-align:${b.align}`);
    if(b.fontWeight) s.push(`font-weight:${b.fontWeight}`);
    if(b.fontStyle) s.push(`font-style:${b.fontStyle}`);
    if(b.lineHeight) s.push(`line-height:${b.lineHeight}`);
    return s.join(';');
  }

  function innerHtml(b,editable=true){
    const ce=editable?' contenteditable="true"':'';
    const cls=editable?'nl-editable':'';

    if(b.type==='logo'){
      return `<div style="text-align:${b.align||'center'}">${
        b.src
          ? `<img src="${esc(b.src)}" alt="${esc(b.alt||'Logo')}" style="width:${Number(b.width)||190}px;max-width:100%;height:auto">`
          : `<div style="font-size:28px;font-weight:900;color:#f4512c">${esc(text(b.text||'Hive Colab'))}</div>`
      }</div>`;
    }

    if(b.type==='topbar'){
      const fontWeight=b.fontWeight||'900';
      const leftAlign=b.leftAlign||'left';
      const rightAlign=b.rightAlign||'right';

      return `<div style="display:grid;grid-template-columns:${b.right?'1fr 1fr':'1fr'};font-family:${b.fontFamily||'Montserrat, Arial, sans-serif'};font-size:${Number(b.fontSize)||18}px;font-weight:${fontWeight};color:${b.color||'#ffffff'}">
        <div
          class="${cls}"
          ${ce}
          data-edit-key="left"
          style="background:${b.leftBg||'#f4512c'};padding:12px 20px;text-align:${leftAlign}"
        >${esc(text(b.left||'Newsletter'))}</div>
        ${b.right?`<div
          class="${cls}"
          ${ce}
          data-edit-key="right"
          style="background:${b.rightBg||'#ff9515'};padding:12px 20px;text-align:${rightAlign}"
        >${esc(text(b.right))}</div>`:''}
      </div>`;
    }

    if(b.type==='heading'){
      return `<h2 class="${cls}"${ce} data-edit-key="text" style="margin:0;line-height:1.25;${textStyle(b)}">${esc(text(b.text||'Heading'))}</h2>`;
    }

    if(b.type==='text'){
      return `<div class="${cls}"${ce} data-edit-key="content" style="white-space:pre-line;${textStyle(b)}">${esc(text(b.content||'Text'))}</div>`;
    }

    if(b.type==='image'){
      return b.src
        ? `<div style="text-align:${b.align||'center'}"><img src="${esc(b.src)}" alt="${esc(b.alt||'')}" style="width:${Number(b.width)||100}%;max-width:100%;height:auto;border-radius:${Number(b.radius)||0}px"></div>`
        : `<div class="nl-drop-empty"><div><i class="fa fa-image"></i><p>Upload image in settings</p></div></div>`;
    }

    if(b.type==='button'){
      return `<div style="text-align:${b.align||'left'}"><a href="${esc(b.url||'#')}" style="display:inline-block;background:${b.bgcolor||'#f4512c'};color:${b.color||'#fff'};padding:13px 16px;width:${Number(b.width)||210}px;max-width:100%;box-sizing:border-box;text-align:center;text-decoration:none;border-radius:${Number(b.radius)||0}px">${esc(text(b.text||'Button'))}</a></div>`;
    }

    if(b.type==='divider'){
      return `<hr style="margin:0;border:0;border-top:${Number(b.thickness)||2}px ${b.style||'solid'} ${b.color||'#f7941d'}">`;
    }

    if(b.type==='spacer'){
      return `<div style="height:${Number(b.height)||28}px"></div>`;
    }

    if(b.type==='columns'){
      const gap=Number(b.gap)||18;
      const halfGap=Math.round(gap/2);

      return `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="nl-col-table"${backgroundColorAttr(b)} style="${textStyle(b)};${backgroundOnlyStyle(b)}"><tr>
        <td class="nl-col" style="width:50%;vertical-align:top;padding:0 ${halfGap}px 0 0">
          <div class="${cls}"${ce} data-edit-key="col1.content">${esc(text(b.col1?.content||''))}</div>
        </td>
        <td class="nl-col" style="width:50%;vertical-align:top;padding:0 0 0 ${halfGap}px">
          <div class="${cls}"${ce} data-edit-key="col2.content">${esc(text(b.col2?.content||''))}</div>
        </td>
      </tr></table>`;
    }

    if(b.type==='section'){
      const title=`<h2 class="${cls}"${ce} data-edit-key="title" style="margin:0 0 8px;color:${b.titleColor||'#333'}">${esc(text(b.title||'Story title'))}</h2>`;
      const meta=`<div class="${cls}"${ce} data-edit-key="meta" style="font-size:12px;color:#9ca3af;margin-bottom:14px">${esc(text(b.meta||''))}</div>`;
      const body=`<div class="${cls}"${ce} data-edit-key="body" style="white-space:pre-line;line-height:1.5">${esc(text(b.body||''))}</div>`;
      const img=b.img
        ? `<img src="${esc(b.img)}" alt="" style="width:100%;height:auto;display:block">`
        : `<div class="nl-drop-empty"><div><i class="fa fa-image"></i><p>Story image</p></div></div>`;
      let content='';
      if(b.columns==='image-above') content=`${img}<div style="margin-top:16px">${title}${meta}${body}</div>`;
      else if(b.columns==='image-below') content=`${title}${meta}${body}<div style="margin-top:16px">${img}</div>`;
      else {
        /*
         * Two-column "image beside text" layout, built with a table
         * instead of CSS grid/flexbox. Email clients have very
         * inconsistent support for grid/flexbox (Outlook desktop
         * supports neither at all), but a plain HTML table with
         * valign works everywhere. The .nl-col class is targeted by
         * a media query in buildEmailHtml() so these columns stack
         * into a single column on narrow screens/mobile mail apps,
         * matching the same layout used for "image above/below".
         */
        const imgCell=`<td class="nl-col" style="width:50%;vertical-align:top;padding:0 10px 0 0">${img}</td>`;
        const textCell=`<td class="nl-col" style="width:50%;vertical-align:top;padding:0 0 0 10px">${body}</td>`;
        const imgCellRight=`<td class="nl-col" style="width:50%;vertical-align:top;padding:0 0 0 10px">${img}</td>`;
        const textCellLeft=`<td class="nl-col" style="width:50%;vertical-align:top;padding:0 10px 0 0">${body}</td>`;

        const row=
          b.columns==='text-left'
            ? textCellLeft+imgCellRight
            : imgCell+textCell;

        content=`${title}${meta}<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="nl-col-table"${backgroundColorAttr(b)} style="${backgroundOnlyStyle(b)}"><tr>${row}</tr></table>`;
      }

      /*
       * NOTE: The outer .nl-block wrapper already renders this block's
       * background via blockStyle() using the exact same
       * backgroundType/backgroundColor/backgroundImage fields as the
       * "Section Background" settings panel. Do NOT also paint a
       * background here — doing so duplicated the same colour/image
       * on two stacked layers and made the section background
       * settings look unreliable (e.g. an image appearing to "not
       * apply" because it was hidden directly behind an identical
       * copy of itself, or a colour swap looking like it did nothing).
       */
      return `<div style="border-left:4px solid ${b.accent||'#f4512c'};padding:18px 20px">${content}</div>`;
    }

    if(b.type==='cta'){
      return `<div style="border-left:4px solid ${b.accent||'#ff9515'};background:${b.backgroundColor||'#fff'};padding:22px">
        <h2 class="${cls}"${ce} data-edit-key="title">${esc(text(b.title||''))}</h2>
        <div class="${cls}"${ce} data-edit-key="body" style="white-space:pre-line">${esc(text(b.body||''))}</div>
        <div class="${cls}"${ce} data-edit-key="highlight" style="margin-top:14px;color:${b.highlightColor||'#f4512c'};font-weight:800">${esc(text(b.highlight||''))}</div>
        <div class="${cls}"${ce} data-edit-key="signature" style="margin-top:10px;font-style:italic">${esc(text(b.signature||''))}</div>
      </div>`;
    }

    if(b.type==='logoFooter'){
      const logoCell=
        b.logo
          ? `<img src="${esc(b.logo)}" alt="" style="width:${Number(b.logoWidth)||330}px;max-width:100%;height:auto;display:block">`
          : `<div style="font-size:28px;font-weight:900;color:${b.color||'#f7941d'}">${esc(text(b.logoText||'Hive Colab'))}</div>`;

      const infoCell=`<div style="color:${b.mutedColor||'#bdbdbd'}">
        <div class="${cls}"${ce} data-edit-key="address">${esc(text(b.address||''))}</div>
        <div class="${cls}"${ce} data-edit-key="socials" style="margin-top:6px;color:${b.color||'#f7941d'}">${esc(text(b.socials||''))}</div>
        <a href="${esc(b.websiteUrl||'#')}" style="color:#60a5fa;text-decoration:none">${esc(text(b.website||''))}</a>
      </div>`;

      /*
       * Same fix as the "section"/"columns" blocks: a table instead
       * of CSS grid, so this renders correctly across every email
       * client (Outlook desktop included) rather than silently
       * falling back to each column stacking full-width on its own -
       * which is exactly the broken layout being reported here (logo,
       * then address, then socials, then website, each on their own
       * row instead of side by side).
       *
       * The .nl-col-table/.nl-col classes reuse the same mobile
       * media query added for those blocks, so this also stacks
       * cleanly on narrow screens instead of squeezing two columns
       * into a phone-width footer.
       *
       * The outer .nl-block wrapper already paints this block's
       * background via blockStyle(), using the same
       * backgroundColor/backgroundImage/backgroundType fields as the
       * "Block Background" settings panel - so, as with the section
       * block, we do NOT also hardcode a background here. Doing so
       * previously ignored a "None" or "Image" background choice and
       * always forced a solid colour behind the logo/info.
       */
      return `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="nl-col-table"${backgroundColorAttr(b)} style="${backgroundOnlyStyle(b)}"><tr>
        <td class="nl-col" style="width:50%;vertical-align:middle;padding:0 11px 0 0">${logoCell}</td>
        <td class="nl-col" style="width:50%;vertical-align:middle;padding:0 0 0 11px">${infoCell}</td>
      </tr></table>`;
    }

    return '';
  }

  function blockHtml(b){
    return `<div class="nl-block ${selectedId===b.id?'selected':''}" draggable="true" data-id="${esc(b.id)}" style="${blockStyle(b)}">
      <div class="nl-block-toolbar">
        <button type="button" onclick="NL.duplicateBlock('${esc(b.id)}')" title="Duplicate"><i class="fa fa-copy"></i></button>
        <button type="button" onclick="NL.deleteBlock('${esc(b.id)}')" title="Delete"><i class="fa fa-trash"></i></button>
      </div>
      <div class="nl-block-content">${innerHtml(b,true)}</div>
    </div>`;
  }

  function renderCanvas(){
    const canvas=$('nlCanvas');
    if(!canvas) return;

    if(!blocks.length){
      canvas.innerHTML='<div class="nl-drop-empty"><div><i class="fa fa-hand-pointer"></i><p>Drag blocks here to start designing</p></div></div>';
      bindCanvasDnD();
      return;
    }

    canvas.innerHTML=blocks.map(blockHtml).join('');

    canvas.querySelectorAll('.nl-block').forEach(el=>{
      el.addEventListener('click',e=>{
        if(!e.target.closest('.nl-block-toolbar')){
          e.stopPropagation();
          selectBlock(el.dataset.id);
        }
      });

      el.addEventListener('dragstart',e=>{
        draggedBlockId=el.dataset.id;
        el.classList.add('dragging');
        e.dataTransfer.setData('text/plain','reorder:'+draggedBlockId);
      });

      el.addEventListener('dragend',()=>{
        el.classList.remove('dragging');
        removeMarker();
      });
    });

    canvas.querySelectorAll('.nl-editable').forEach(el=>{
      el.addEventListener('input',e=>{
        const b=blocks.find(x=>x.id===selectedId);
        if(!b) return;
        setDeep(b,e.target.dataset.editKey,e.target.innerText);
      });
    });

    bindCanvasDnD();
  }

  function bindCanvasDnD(){
    const canvas=$('nlCanvas');
    if(!canvas || canvas.dataset.bound==='1') return;
    canvas.dataset.bound='1';

    document.querySelectorAll('[data-block-type]').forEach(btn=>{
      btn.addEventListener('dragstart',e=>{
        e.dataTransfer.setData('text/plain','new:'+btn.dataset.blockType);
      });
      btn.addEventListener('dragend',()=>{
        btn.dataset.justDragged='1';
        setTimeout(()=>btn.dataset.justDragged='',450);
      });
    });

    canvas.addEventListener('dragover',e=>{
      e.preventDefault();
      showDropMarker(e);
    });

    canvas.addEventListener('drop',e=>{
      e.preventDefault();
      const data=e.dataTransfer.getData('text/plain');
      const index=getMarkerIndex();
      removeMarker();

      if(data.startsWith('new:')) insertNewBlock(data.slice(4),index);
      if(data.startsWith('reorder:')) reorderBlock(data.slice(8),index);
    });
  }

  function showDropMarker(e){
    const canvas=$('nlCanvas');
    if(!canvas) return;
    if(!dropMarker){
      dropMarker=document.createElement('div');
      dropMarker.className='nl-drop-marker';
    }

    const items=[...canvas.querySelectorAll('.nl-block:not(.dragging)')];
    let before=null;

    for(const item of items){
      const r=item.getBoundingClientRect();
      if(e.clientY<r.top+r.height/2){
        before=item;
        break;
      }
    }

    if(before) canvas.insertBefore(dropMarker,before);
    else canvas.appendChild(dropMarker);
  }

  function getMarkerIndex(){
    if(!dropMarker?.parentNode) return blocks.length;
    return [...$('nlCanvas').children].indexOf(dropMarker);
  }

  function removeMarker(){
    dropMarker?.remove();
  }

  function insertNewBlock(type,index){
    const b={id:uid(),...defaultData(type)};
    blocks.splice(Math.max(0,index),0,b);
    renderCanvas();
    selectBlock(b.id);
  }

  function reorderBlock(id,index){
    const old=blocks.findIndex(b=>b.id===id);
    if(old<0) return;
    const [b]=blocks.splice(old,1);
    if(index>old) index--;
    blocks.splice(Math.max(0,index),0,b);
    renderCanvas();
    selectBlock(id);
  }

  function selectBlock(id){
    selectedId=id;
    renderCanvas();
    const b=blocks.find(x=>x.id===id);
    if(!b) return settingsEmpty();
    renderSettings(b);
  }

  function settingsEmpty(){
    if($('nlSettingsEmpty')) $('nlSettingsEmpty').style.display='';
    if($('nlSettingsContent')){
      $('nlSettingsContent').style.display='none';
      $('nlSettingsContent').innerHTML='';
    }
  }

  function duplicateBlock(id){
    const index=blocks.findIndex(b=>b.id===id);
    if(index<0) return;
    const copy=clone(blocks[index]);
    copy.id=uid();
    blocks.splice(index+1,0,copy);
    renderCanvas();
    selectBlock(copy.id);
  }

  function deleteBlock(id){
    blocks=blocks.filter(b=>b.id!==id);
    if(selectedId===id){
      selectedId=null;
      settingsEmpty();
    }
    renderCanvas();
  }

  function input(label,key,b,value,type='text'){
    return `<div class="nl-form-group"><label>${esc(label)}</label><input type="${type}" class="nl-form-control" data-bid="${esc(b.id)}" data-key="${esc(key)}" value="${esc(value??'')}"></div>`;
  }

  function textarea(label,key,b,value){
    return `<div class="nl-form-group"><label>${esc(label)}</label><textarea class="nl-form-control" rows="3" data-bid="${esc(b.id)}" data-key="${esc(key)}">${esc(value??'')}</textarea></div>`;
  }

  function imageUpload(label,b,key='src'){
    return `<div class="nl-form-group"><label>${esc(label)}</label><div class="img-upload-zone"><input type="file" accept="image/*" onchange="NL.handleBlockImageUpload(event,'${esc(b.id)}','${esc(key)}')"><i class="fa fa-cloud-upload-alt"></i><strong>Click to upload</strong></div>${b[key]?`<img class="img-thumb" src="${esc(b[key])}" alt="">`:''}</div>`;
  }

  function selectInput(label,key,b,value,options){
    return `<div class="nl-form-group">
      <label>${esc(label)}</label>
      <select class="nl-form-control" data-bid="${esc(b.id)}" data-key="${esc(key)}">
        ${options.map(option=>{
          const val=Array.isArray(option)?option[0]:option;
          const lbl=Array.isArray(option)?option[1]:option;
          return `<option value="${esc(val)}" ${String(value)===String(val)?'selected':''}>${esc(lbl)}</option>`;
        }).join('')}
      </select>
    </div>`;
  }


  function backgroundSettings(b,label='Block Background'){
    const type=b.backgroundType || (
      b.backgroundImage
        ? 'image'
        : (
            b.backgroundColor && b.backgroundColor!=='transparent'
              ? 'color'
              : 'none'
          )
    );

    return `
      <div class="nl-settings-group nl-background-settings">
        <div class="nl-settings-subtitle">
          <i class="fa fa-fill-drip"></i>
          ${esc(label)}
        </div>

        <div class="nl-bg-choice-row">
          <button
            type="button"
            class="nl-bg-choice ${type==='none'?'active':''}"
            onclick="NL.setBlockBackgroundType('${esc(b.id)}','none')"
          >
            <i class="fa fa-ban"></i>
            None
          </button>

          <button
            type="button"
            class="nl-bg-choice ${type==='color'?'active':''}"
            onclick="NL.setBlockBackgroundType('${esc(b.id)}','color')"
          >
            <i class="fa fa-palette"></i>
            Colour
          </button>

          <button
            type="button"
            class="nl-bg-choice ${type==='image'?'active':''}"
            onclick="NL.setBlockBackgroundType('${esc(b.id)}','image')"
          >
            <i class="fa fa-image"></i>
            Image
          </button>
        </div>

        ${type==='color' ? `
          <div class="nl-form-group">
            <label>Background Colour</label>
            <div class="nl-colour-control">
              <input
                type="color"
                class="nl-colour-picker"
                data-bid="${esc(b.id)}"
                data-key="backgroundColor"
                value="${esc(/^#[0-9A-Fa-f]{6}$/.test(b.backgroundColor||'')?b.backgroundColor:'#ffffff')}"
              >
              <input
                type="text"
                class="nl-form-control"
                data-bid="${esc(b.id)}"
                data-key="backgroundColor"
                value="${esc(b.backgroundColor||'#ffffff')}"
                placeholder="#ffffff"
              >
            </div>
          </div>
        ` : ''}

        ${type==='image' ? `
          <div class="nl-form-group">
            <label>Background Image</label>

            <div class="img-upload-zone nl-background-upload">
              <input
                type="file"
                accept="image/png,image/jpeg,image/webp,image/gif"
                onchange="NL.handleBackgroundUpload(event,'${esc(b.id)}')"
              >
              <i class="fa fa-cloud-upload-alt"></i>
              <strong>${b.backgroundImage?'Replace background image':'Upload background image'}</strong>
              <small>JPG, PNG, WEBP or GIF</small>
            </div>

            ${b.backgroundImage ? `
              <div class="nl-bg-image-preview">
                <img
                  src="${esc(b.backgroundImage)}"
                  alt="Background preview"
                >
                <button
                  type="button"
                  class="nl-btn nl-btn-sm nl-btn-danger"
                  onclick="NL.removeBlockBackgroundImage('${esc(b.id)}')"
                >
                  <i class="fa fa-trash"></i>
                  Remove Image
                </button>
              </div>
            ` : ''}

            ${selectInput(
              'Background Size',
              'backgroundSize',
              b,
              b.backgroundSize||'cover',
              [
                ['cover','Cover'],
                ['contain','Contain'],
                ['auto','Original size']
              ]
            )}

            ${selectInput(
              'Background Position',
              'backgroundPosition',
              b,
              b.backgroundPosition||'center center',
              [
                ['left top','Top left'],
                ['center top','Top centre'],
                ['right top','Top right'],
                ['left center','Centre left'],
                ['center center','Centre'],
                ['right center','Centre right'],
                ['left bottom','Bottom left'],
                ['center bottom','Bottom centre'],
                ['right bottom','Bottom right']
              ]
            )}

            ${selectInput(
              'Background Repeat',
              'backgroundRepeat',
              b,
              b.backgroundRepeat||'no-repeat',
              [
                ['no-repeat','No repeat'],
                ['repeat','Repeat'],
                ['repeat-x','Repeat horizontally'],
                ['repeat-y','Repeat vertically']
              ]
            )}
          </div>
        ` : ''}
      </div>
    `;
  }

  function sectionBackgroundSettings(b){
    const type=b.backgroundType || (b.backgroundImage?'image':'color');

    return `
      <div class="nl-settings-group nl-section-background-settings">
        <div class="nl-settings-subtitle">
          <i class="fa fa-fill-drip"></i>
          Section Background
        </div>

        <div class="nl-bg-choice-row" role="group" aria-label="Section background type">
          <button
            type="button"
            class="nl-bg-choice ${type==='none'?'active':''}"
            onclick="NL.setSectionBackgroundType('${esc(b.id)}','none')"
          >
            <i class="fa fa-ban"></i>
            None
          </button>

          <button
            type="button"
            class="nl-bg-choice ${type==='color'?'active':''}"
            onclick="NL.setSectionBackgroundType('${esc(b.id)}','color')"
          >
            <i class="fa fa-palette"></i>
            Colour
          </button>

          <button
            type="button"
            class="nl-bg-choice ${type==='image'?'active':''}"
            onclick="NL.setSectionBackgroundType('${esc(b.id)}','image')"
          >
            <i class="fa fa-image"></i>
            Image
          </button>
        </div>

        ${type==='color' ? `
          <div class="nl-form-group">
            <label>Background Colour</label>
            <div class="nl-colour-control">
              <input
                type="color"
                class="nl-colour-picker"
                data-bid="${esc(b.id)}"
                data-key="backgroundColor"
                value="${esc(/^#[0-9A-Fa-f]{6}$/.test(b.backgroundColor||'')?b.backgroundColor:'#fff7f4')}"
              >
              <input
                type="text"
                class="nl-form-control"
                data-bid="${esc(b.id)}"
                data-key="backgroundColor"
                value="${esc(b.backgroundColor||'#fff7f4')}"
                placeholder="#fff7f4"
              >
            </div>
          </div>
        ` : ''}

        ${type==='image' ? `
          <div class="nl-form-group">
            <label>Background Image</label>

            <div class="img-upload-zone nl-background-upload">
              <input
                type="file"
                accept="image/png,image/jpeg,image/webp,image/gif"
                onchange="NL.handleBackgroundUpload(event,'${esc(b.id)}')"
              >
              <i class="fa fa-cloud-upload-alt"></i>
              <strong>${b.backgroundImage?'Replace background image':'Upload background image'}</strong>
              <small>JPG, PNG, WEBP or GIF</small>
            </div>

            ${b.backgroundImage ? `
              <div class="nl-bg-image-preview">
                <img src="${esc(b.backgroundImage)}" alt="Section background preview">
                <button
                  type="button"
                  class="nl-btn nl-btn-sm nl-btn-danger"
                  onclick="NL.removeSectionBackgroundImage('${esc(b.id)}')"
                >
                  <i class="fa fa-trash"></i>
                  Remove Image
                </button>
              </div>
            ` : `
              <div class="nl-muted-xs" style="margin-top:7px">
                Upload an image and it will appear behind this section.
              </div>
            `}
          </div>

          ${selectInput(
            'Background Size',
            'backgroundSize',
            b,
            b.backgroundSize||'cover',
            [
              ['cover','Cover'],
              ['contain','Contain'],
              ['auto','Original size']
            ]
          )}

          ${selectInput(
            'Background Position',
            'backgroundPosition',
            b,
            b.backgroundPosition||'center center',
            [
              ['left top','Top left'],
              ['center top','Top centre'],
              ['right top','Top right'],
              ['left center','Centre left'],
              ['center center','Centre'],
              ['right center','Centre right'],
              ['left bottom','Bottom left'],
              ['center bottom','Bottom centre'],
              ['right bottom','Bottom right']
            ]
          )}

          ${selectInput(
            'Background Repeat',
            'backgroundRepeat',
            b,
            b.backgroundRepeat||'no-repeat',
            [
              ['no-repeat','No repeat'],
              ['repeat','Repeat'],
              ['repeat-x','Repeat horizontally'],
              ['repeat-y','Repeat vertically']
            ]
          )}
        ` : ''}
      </div>
    `;
  }

  function renderSettings(b){
    const box=$('nlSettingsContent');
    if(!box) return;

    $('nlSettingsEmpty').style.display='none';
    box.style.display='';

    let html=`<div class="nl-settings-title"><i class="fa fa-sliders-h"></i> ${esc(b.type)} Settings</div>`;

    if(b.fontFamily!==undefined){
      html+=`<div class="nl-form-group"><label>Font</label><select class="nl-form-control" data-bid="${esc(b.id)}" data-key="fontFamily">${fonts.map(f=>`<option value="${esc(f[0])}" ${b.fontFamily===f[0]?'selected':''}>${esc(f[1])}</option>`).join('')}</select></div>`;
      html+=input('Font Size','fontSize',b,b.fontSize,'number');
    }

    if(b.type==='logo'){
      html+=imageUpload('Logo',b,'src')+input('Logo Width','width',b,b.width,'number');
    }

    if(b.type==='topbar'){
      html+=textarea('Left Text','left',b,b.left||'');
      html+=textarea('Right Text','right',b,b.right||'');
      html+=input('Left Background Colour','leftBg',b,b.leftBg||'#f4512c','color');
      html+=input('Right Background Colour','rightBg',b,b.rightBg||'#ff9515','color');
      html+=input('Text Colour','color',b,b.color||'#ffffff','color');

      html+=selectInput(
        'Font Weight',
        'fontWeight',
        b,
        b.fontWeight||'900',
        [
          ['400','Regular'],
          ['600','Semi Bold'],
          ['700','Bold'],
          ['800','Extra Bold'],
          ['900','Black']
        ]
      );

      html+=selectInput(
        'Left Text Alignment',
        'leftAlign',
        b,
        b.leftAlign||'left',
        [
          ['left','Left'],
          ['center','Centre'],
          ['right','Right']
        ]
      );

      html+=selectInput(
        'Right Text Alignment',
        'rightAlign',
        b,
        b.rightAlign||'right',
        [
          ['left','Left'],
          ['center','Centre'],
          ['right','Right']
        ]
      );

      html+=backgroundSettings(b,'Top Bar Outer Background');
    }

    if(b.type==='heading') html+=textarea('Heading','text',b,b.text);
    if(b.type==='text') html+=textarea('Content','content',b,b.content);
    if(b.type==='image') html+=imageUpload('Image',b,'src')+input('Link URL','link',b,b.link);
    if(b.type==='button') html+=input('Button Text','text',b,b.text)+input('Button URL','url',b,b.url)+input('Button Colour','bgcolor',b,b.bgcolor,'color');
    if(b.type==='divider') html+=input('Line Colour','color',b,b.color,'color')+input('Thickness','thickness',b,b.thickness,'number');
    if(b.type==='spacer') html+=input('Height','height',b,b.height,'number');
    if(b.type==='columns') html+=textarea('Left Column','col1.content',b,b.col1?.content)+textarea('Right Column','col2.content',b,b.col2?.content);
    if(b.type==='section'){
      html+=textarea('Title','title',b,b.title);
      html+=input('Meta','meta',b,b.meta);
      html+=textarea('Body','body',b,b.body);
      html+=imageUpload('Story Image',b,'img');

      html+=selectInput(
        'Story Layout',
        'columns',
        b,
        b.columns||'image-left',
        [
          ['image-left','Image left / Text right'],
          ['text-left','Text left / Image right'],
          ['image-above','Image above text'],
          ['image-below','Image below text']
        ]
      );

      html+=input('Accent Colour','accent',b,b.accent||'#f4512c','color');
      html+=input('Title Colour','titleColor',b,b.titleColor||'#333333','color');

      html+=sectionBackgroundSettings(b);

      html+=input('Button Text','buttonText',b,b.buttonText);
      html+=input('Button URL','buttonUrl',b,b.buttonUrl);
    }
    if(b.type==='cta') html+=textarea('Title','title',b,b.title)+textarea('Body','body',b,b.body)+input('Highlight','highlight',b,b.highlight)+input('Signature','signature',b,b.signature);
    if(b.type==='logoFooter') html+=imageUpload('Footer Logo',b,'logo')+input('Logo Text','logoText',b,b.logoText)+textarea('Address','address',b,b.address)+input('Social Text','socials',b,b.socials)+input('Website','website',b,b.website)+input('Website URL','websiteUrl',b,b.websiteUrl);

    const supportsGenericBackground = [
      'logo',
      'heading',
      'text',
      'image',
      'button',
      'divider',
      'spacer',
      'columns',
      'cta',
      'logoFooter'
    ].includes(b.type);

    if(supportsGenericBackground){
      html+=backgroundSettings(b,'Block Background');
    }

    box.innerHTML=html;

    box.querySelectorAll('[data-bid]').forEach(el=>{
      const update=e=>{
        const block=blocks.find(x=>x.id===e.target.dataset.bid);
        if(!block) return;

        let value=e.target.value;

        if(e.target.type==='number'){
          value=Number(value);
        }

        setDeep(block,e.target.dataset.key,value);
        renderCanvas();

        // Keep the selected block visually selected while editing controls.
        selectedId=block.id;
      };
      el.addEventListener('input',update);
      el.addEventListener('change',update);
    });
  }

  function setDeep(obj,path,value){
    const parts=path.split('.');
    let cur=obj;
    while(parts.length>1){
      const p=parts.shift();
      cur[p]=cur[p]||{};
      cur=cur[p];
    }
    cur[parts[0]]=value;
  }

  /*
   * ------------------------------------------------------------------
   * IMAGE UPLOADS
   * ------------------------------------------------------------------
   * All block images (logo, story image, inline image blocks) and all
   * per-block/section background images are uploaded to the server's
   * `upload_image` action, which resizes and compresses them before
   * storing them on disk. We keep only the short returned URL on the
   * block object.
   *
   * Previously these handlers read the file as a base64 data URL and
   * embedded that directly into the block's data, which then got
   * saved verbatim inside body_json/body_html. That meant every image
   * a user added was stored uncompressed, at full original size,
   * duplicated as text inside the newsletter JSON — making newsletters
   * heavy, slow to save/load, and prone to silently failing when the
   * combined payload got too large for the server or database to
   * accept (which is what made background colours/images on sections
   * look unreliable).
   * ------------------------------------------------------------------
   */

  function uploadImageToServer(file){
    const fd=new FormData();
    fd.set('newsletter_action','upload_image');
    fd.set('action','upload_image');
    fd.set('ajax','1');
    fd.set('image',file);

    return fetch(PROCESS_URL,{
      method:'POST',
      body:fd,
      headers:{
        'X-Requested-With':'XMLHttpRequest',
        'Accept':'application/json'
      }
    }).then(async response=>{
      const raw=await response.text();
      let json=null;

      try{
        json=JSON.parse(raw);
      }catch(e){
        const clean=String(raw||'')
          .replace(/<[^>]*>/g,' ')
          .replace(/\s+/g,' ')
          .trim();

        throw new Error(
          clean
            ? clean.substring(0,250)
            : 'Image upload returned an invalid response.'
        );
      }

      if(!response.ok || !json.ok){
        throw new Error(json.message||'Image upload failed.');
      }

      return json;
    });
  }

  function setUploadZoneBusy(zone,busy,label){
    if(!zone) return;

    zone.classList.toggle('is-uploading',!!busy);
    zone.style.opacity=busy?'0.55':'';
    zone.style.pointerEvents=busy?'none':'';

    const strong=zone.querySelector('strong');
    if(!strong) return;

    if(busy){
      if(!strong.dataset.originalText){
        strong.dataset.originalText=strong.textContent;
      }
      strong.textContent=label||'Uploading...';
    }else if(strong.dataset.originalText){
      strong.textContent=strong.dataset.originalText;
    }
  }

  function handleBlockImageUpload(event,bid,key='src'){
    const file=event.target.files?.[0];
    if(!file) return;

    if(!/^image\//i.test(file.type||'')){
      alert('Please select an image file.');
      event.target.value='';
      return;
    }

    const zone=event.target.closest('.img-upload-zone');
    setUploadZoneBusy(zone,true,'Uploading...');

    uploadImageToServer(file)
      .then(json=>{
        const b=blocks.find(x=>x.id===bid);
        if(!b) return;

        b[key]=json.url || json.path || '';

        renderCanvas();
        selectBlock(bid);
      })
      .catch(err=>{
        console.error('Newsletter image upload error:',err);
        alert(err.message||'Image upload failed.');
      })
      .finally(()=>{
        setUploadZoneBusy(zone,false);
        event.target.value='';
      });
  }

  function dataUrlToBlob(dataUrl){
    const commaIdx=dataUrl.indexOf(',');
    const header=dataUrl.substring(0,commaIdx);
    const base64=dataUrl.substring(commaIdx+1);
    const mimeMatch=/data:([^;]+);base64/i.exec(header);
    const mime=mimeMatch?mimeMatch[1]:'image/png';

    const binary=atob(base64);
    const len=binary.length;
    const bytes=new Uint8Array(len);

    for(let i=0;i<len;i++){
      bytes[i]=binary.charCodeAt(i);
    }

    return new Blob([bytes],{type:mime});
  }

  function extensionForMime(mime){
    const map={
      'image/jpeg':'jpg',
      'image/png':'png',
      'image/gif':'gif',
      'image/webp':'webp'
    };
    return map[mime]||'png';
  }

  /*
   * Newsletters created/edited before images were uploaded-and-URLed
   * (see uploadImageToServer above) may still carry old images
   * embedded directly as base64 data URIs inside a block's data
   * (b.src, b.img, b.logo, b.backgroundImage). Re-saving one of these
   * as-is means resending that entire base64 blob - often several
   * megabytes per image - on every single save, which is what makes
   * "Save Draft" look like it hangs: the browser is just still
   * uploading a huge multipart body with nothing to show for it.
   *
   * This scans the given blocks for any such leftover base64 image,
   * uploads it through the normal (resizing + compressing)
   * upload_image endpoint, and swaps in the short returned URL - so
   * every save/send benefits from the same lightweight-image fix,
   * even for newsletters that predate it.
   */
  async function migrateLegacyImages(list){
    if(!Array.isArray(list) || !list.length) return 0;

    const CONTENT_IMAGE_KEYS={
      logo:['src'],
      image:['src'],
      section:['img'],
      logoFooter:['logo']
    };

    const tasks=[];

    list.forEach(b=>{
      if(!b || typeof b!=='object') return;

      const keys=(CONTENT_IMAGE_KEYS[b.type]||[]).slice();

      if(typeof b.backgroundImage==='string' && b.backgroundImage){
        keys.push('backgroundImage');
      }

      keys.forEach(key=>{
        const val=b[key];

        if(typeof val!=='string' || !val.startsWith('data:image/')){
          return;
        }

        const task=(async()=>{
          try{
            const blob=dataUrlToBlob(val);
            const file=new File(
              [blob],
              'legacy-image.'+extensionForMime(blob.type),
              {type:blob.type}
            );

            const json=await uploadImageToServer(file);
            b[key]=json.url || json.path || '';
          }catch(err){
            console.error(
              'Legacy image migration failed for block',
              b.id,
              key,
              err
            );
            /*
             * Leave the original value in place rather than blocking
             * the whole save indefinitely. The server-side size guard
             * on the save action will still catch anything that ends
             * up too large to store safely.
             */
          }
        })();

        tasks.push(task);
      });
    });

    if(tasks.length){
      await Promise.all(tasks);
    }

    return tasks.length;
  }

  function setBlockBackgroundType(bid,type){
    const b=blocks.find(x=>x.id===bid);
    if(!b) return;

    if(!['none','color','image'].includes(type)){
      type='none';
    }

    b.backgroundType=type;

    if(type==='color'){
      if(!b.backgroundColor || b.backgroundColor==='transparent'){
        b.backgroundColor=b.type==='section' ? '#fff7f4' : '#ffffff';
      }
    }

    if(type==='image'){
      b.backgroundSize=b.backgroundSize||'cover';
      b.backgroundPosition=b.backgroundPosition||'center center';
      b.backgroundRepeat=b.backgroundRepeat||'no-repeat';
    }

    renderCanvas();
    selectBlock(bid);
  }

  function setSectionBackgroundType(bid,type){
    setBlockBackgroundType(bid,type);
  }

  function handleBackgroundUpload(event,bid){
    const file=event.target.files?.[0];
    if(!file) return;

    if(!/^image\//i.test(file.type||'')){
      alert('Please select an image file.');
      event.target.value='';
      return;
    }

    /*
     * Matches the server-side cap for newsletter section/background
     * images in process-newsletters.php (upload_image action).
     */
    const maxBytes=6*1024*1024;

    if(file.size>maxBytes){
      alert('Background image must be 6 MB or smaller.');
      event.target.value='';
      return;
    }

    const zone=event.target.closest('.img-upload-zone');
    setUploadZoneBusy(zone,true,'Uploading...');

    uploadImageToServer(file)
      .then(json=>{
        const b=blocks.find(x=>x.id===bid);
        if(!b) return;

        b.backgroundType='image';
        b.backgroundImage=json.url || json.path || '';
        b.backgroundSize=b.backgroundSize||'cover';
        b.backgroundPosition=b.backgroundPosition||'center center';
        b.backgroundRepeat=b.backgroundRepeat||'no-repeat';

        renderCanvas();
        selectBlock(bid);
      })
      .catch(err=>{
        console.error('Newsletter background upload error:',err);
        alert(err.message||'Background image upload failed.');
      })
      .finally(()=>{
        setUploadZoneBusy(zone,false);
        event.target.value='';
      });
  }

  function removeBlockBackgroundImage(bid){
    const b=blocks.find(x=>x.id===bid);
    if(!b) return;

    b.backgroundImage='';
    b.backgroundType=(
      b.backgroundColor && b.backgroundColor!=='transparent'
    ) ? 'color' : 'none';

    renderCanvas();
    selectBlock(bid);
  }

  function removeSectionBackgroundImage(bid){
    removeBlockBackgroundImage(bid);
  }

  function cleanBlockHtml(b){
    return `<div style="${blockStyle(b)}">${innerHtml(b,false)}</div>`;
  }

  function buildEmailHtml(subject,preheader,fromName){
    return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${esc(text(subject))}</title><style>
      body{margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif}
      .wrap{max-width:794px;margin:0 auto;background:#fff}
      img{max-width:100%;height:auto}
      table{border-collapse:collapse}
      @media(max-width:700px){.wrap{width:100%!important}}

      /*
       * Responsive columns for real email clients.
       *
       * Two-column layouts (the "section" block's image-beside-text
       * variants, and the standalone "columns" block) are built with
       * plain HTML tables rather than CSS grid/flexbox, because grid
       * and flexbox support across email clients is unreliable
       * (Outlook desktop supports neither at all). Tables render the
       * two-column layout correctly everywhere on their own; this
       * media query is what makes them additionally STACK into a
       * single column on narrow screens, matching the same look as
       * the "image above/below" layouts. Clients that ignore <style>
       * media queries (e.g. Outlook desktop) simply keep the
       * side-by-side table layout, which still looks correct - it
       * just doesn't stack.
       */
      @media only screen and (max-width:600px){
        .nl-col-table, .nl-col-table tbody, .nl-col-table tr {
          display:block !important;
          width:100% !important;
        }
        .nl-col {
          display:block !important;
          width:100% !important;
          padding:0 0 14px 0 !important;
          box-sizing:border-box;
        }
        .nl-col:last-child {
          padding-bottom:0 !important;
        }
        .nl-col img {
          width:100% !important;
          height:auto !important;
        }
      }
    </style></head><body>
      <div style="display:none;max-height:0;overflow:hidden">${esc(text(preheader||subject))}</div>
      <div class="wrap">${blocks.map(cleanBlockHtml).join('')}
        <div style="background:#f8fafc;border-top:1px solid #e5e7eb;padding:14px 24px;text-align:center;font-size:11px;color:#9ca3af">
          <p>${esc(text(fromName||''))} · You're receiving this because you're part of our community.</p>
          <p><a href="{unsubscribe_url}" style="color:#9ca3af;text-decoration:none">Unsubscribe</a></p>
        </div>
      </div>
    </body></html>`;
  }

  function showStatus(message,error=false){
    const s=$('nlSaveStatus');
    if(!s) return;
    s.textContent=message;
    s.classList.toggle('is-error',!!error);
  }

  /*
   * form.submit() (unlike a real user click on a <button type="submit">)
   * never carries "which submit button triggered this" information, so
   * the button's name="save_action" value="send"/"draft" pair is simply
   * left out of the POST entirely when submitted this way. The server
   * then falls back to its default ('draft'), so a programmatic submit
   * would silently behave like a draft save no matter which button was
   * actually clicked. This stamps the intended save_action onto a
   * dedicated hidden field so it's always sent correctly regardless of
   * how the form gets submitted.
   */
  function setNativeSaveAction(form,value){
    let el=form.querySelector('#nl_dynamic_save_action');

    if(!el){
      el=document.createElement('input');
      el.type='hidden';
      el.name='save_action';
      el.id='nl_dynamic_save_action';
      form.appendChild(el);
    }

    el.value=value;
  }

  function prepareNLSubmit(event){
    const form=event.currentTarget || $('nlBuilderForm');
    activeSubmitter=event.submitter || activeSubmitter || document.activeElement;

    if(!blocks.length){
      showStatus('Please add at least one block.',true);
      return false;
    }

    const subject=$('nl_subject')?.value.trim()||'';
    const preheader=$('nl_preheader')?.value.trim()||'';
    const fromName=$('nl_from_name')?.value.trim()||'';

    if(activeSubmitter?.value==='send' && !subject){
      showStatus('Please enter a subject.',true);
      $('nl_subject')?.focus();
      return false;
    }

    const isSend=activeSubmitter?.value==='send';

    const isDraft =
      !isSend
      && (
        activeSubmitter?.value === 'draft'
        || activeSubmitter?.dataset?.ajaxSave === '1'
      );

    /*
     * Validate recipients up front for "send" so we don't spend time
     * migrating/uploading images only to fail afterwards on a
     * completely separate, already-known problem (no recipients
     * selected).
     */
    let recipients=null;

    if(isSend){
      recipients=getRecipients('nl');
      if(recipients===false) return false;
    }

    const syncHiddenFields=()=>{
      $('nl_body_json').value=JSON.stringify(blocks);
      $('nl_body_html').value=buildEmailHtml(subject,preheader,fromName);
    };

    /*
     * Sync once immediately so there's always a consistent snapshot
     * even if the migration step below fails outright.
     */
    syncHiddenFields();

    if(activeSubmitter) activeSubmitter.disabled=true;

    showStatus(isSend ? 'Preparing to send...' : 'Optimising images...');

    migrateLegacyImages(blocks)
      .catch(err=>{
        console.error('Legacy image migration failed:',err);
      })
      .then(()=>{
        syncHiddenFields();

        if(isDraft){
          if($('nl_recipient_emails')){
            $('nl_recipient_emails').value='[]';
          }

          if(activeSubmitter?.dataset?.ajaxSave==='1'){
            saveDraftAjax(form,activeSubmitter);
          }else{
            setNativeSaveAction(form,'draft');
            form.submit();
          }
          return;
        }

        if(isSend){
          $('nl_recipient_emails').value=JSON.stringify(recipients);
          setNativeSaveAction(form,'send');
          form.submit();
          return;
        }

        setNativeSaveAction(form,'draft');
        form.submit();
      });

    return false;
  }

  function saveDraftAjax(form,button){
    const fd=new FormData(form);
    fd.set('action','save');
    fd.set('newsletter_action','save');
    fd.set('save_action','draft');
    showStatus('Saving draft...');
    if(button) button.disabled=true;

    /*
     * A save should never be able to sit indefinitely with no
     * feedback. If it hasn't finished within a generous window,
     * cancel it and tell the person clearly what's likely wrong
     * (an old, un-optimised image still baked into the newsletter)
     * instead of leaving "Saving draft..." on screen forever.
     */
    const controller=new AbortController();
    const timeoutMs=45000;
    const timeoutId=setTimeout(()=>controller.abort(),timeoutMs);

    fetch(PROCESS_URL,{
      method:'POST',
      body:fd,
      headers:{'X-Requested-With':'XMLHttpRequest'},
      signal:controller.signal
    })
    .then(async r=>{
      const raw=await r.text();
      let json;
      try{
        json=JSON.parse(raw);
      }catch(e){
        const cleanRaw=String(raw||'')
          .replace(/<[^>]*>/g,' ')
          .replace(/\s+/g,' ')
          .trim();

        if(!r.ok){
          throw new Error(
            cleanRaw
              ? 'Save Draft failed: '+cleanRaw.substring(0,300)
              : 'Save Draft request failed (HTTP '+r.status+').'
          );
        }

        throw new Error(
          cleanRaw
            ? 'Invalid server response: '+cleanRaw.substring(0,300)
            : 'The newsletter processor returned an invalid response.'
        );
      }

      if(!r.ok || !json.ok){
        throw new Error(
          json.message
          || ('Save Draft failed (HTTP ' + r.status + ').')
        );
      }

      return json;
    })
    .then(json=>{
      if(json.id && $('nl_id')) $('nl_id').value=json.id;
      showStatus('Draft saved. Continue editing.');
    })
    .catch(err=>{
      console.error(err);

      const message=
        err?.name==='AbortError'
          ? 'Save is taking too long and was cancelled. This newsletter may still contain a large, un-optimised image - try removing and re-uploading it, then save again.'
          : (err.message||'Draft save failed.');

      showStatus(message,true);
    })
    .finally(()=>{
      clearTimeout(timeoutId);
      if(button) button.disabled=false;
    });
  }


  function hydrateBlock(saved){
    if(!saved || typeof saved!=='object'){
      return null;
    }

    const type=String(saved.type||'').trim();

    if(!type){
      return clone(saved);
    }

    let defaults={};

    try{
      defaults=defaultData(type)||{};
    }catch(e){
      defaults={type};
    }

    const hydrated={
      ...clone(defaults),
      ...clone(saved)
    };

    /*
     * Preserve nested column objects instead of replacing their defaults.
     */
    if(defaults.col1 || saved.col1){
      hydrated.col1={
        ...(defaults.col1||{}),
        ...(saved.col1||{})
      };
    }

    if(defaults.col2 || saved.col2){
      hydrated.col2={
        ...(defaults.col2||{}),
        ...(saved.col2||{})
      };
    }

    /*
     * Background migration for newsletters created before backgroundType
     * existed. Saved image/colour always wins over defaults.
     */
    if(
      saved.backgroundType
      && ['none','color','image'].includes(saved.backgroundType)
    ){
      /*
       * The explicitly saved background type is authoritative.
       */
      hydrated.backgroundType=saved.backgroundType;
    }else if(saved.backgroundImage){
      hydrated.backgroundType='image';
    }else if(
      saved.backgroundColor
      && saved.backgroundColor!=='transparent'
    ){
      hydrated.backgroundType='color';
    }else{
      hydrated.backgroundType=hydrated.backgroundType||'none';
    }

    /*
     * Never lose saved background values during hydration, including
     * duplicates created from older newsletters.
     */
    if(saved.backgroundColor!==undefined){
      hydrated.backgroundColor=saved.backgroundColor;
    }

    if(saved.backgroundImage!==undefined){
      hydrated.backgroundImage=saved.backgroundImage;
    }

    hydrated.backgroundPosition=
      saved.backgroundPosition
      || hydrated.backgroundPosition
      || 'center center';

    hydrated.backgroundSize=
      saved.backgroundSize
      || hydrated.backgroundSize
      || 'cover';

    hydrated.backgroundRepeat=
      saved.backgroundRepeat
      || hydrated.backgroundRepeat
      || 'no-repeat';

    /*
     * Top Bar migration: never reset saved colours/text on duplicated
     * or legacy newsletters.
     */
    if(type==='topbar'){
      hydrated.left=
        saved.left!==undefined
          ? saved.left
          : hydrated.left;

      hydrated.right=
        saved.right!==undefined
          ? saved.right
          : hydrated.right;

      hydrated.leftBg=
        saved.leftBg
        || hydrated.leftBg
        || '#f4512c';

      hydrated.rightBg=
        saved.rightBg
        || hydrated.rightBg
        || '#ff9515';

      hydrated.color=
        saved.color
        || hydrated.color
        || '#ffffff';

      hydrated.fontWeight=
        saved.fontWeight
        || hydrated.fontWeight
        || '900';

      hydrated.leftAlign=
        saved.leftAlign
        || hydrated.leftAlign
        || 'left';

      hydrated.rightAlign=
        saved.rightAlign
        || hydrated.rightAlign
        || 'right';
    }

    return hydrated;
  }

  function hydrateBlocks(savedBlocks){
    if(!Array.isArray(savedBlocks)){
      return [];
    }

    return savedBlocks
      .map(hydrateBlock)
      .filter(Boolean);
  }

  function editNewsletter(d){
    resetBuilder();
    const data=clone(d);

    $('nlModalTitle').innerHTML='<i class="fa fa-pen"></i> Edit Newsletter';
    $('nl_id').value=data.id||'';
    $('nl_duplicated_from').value=data.duplicated_from||'';
    $('nl_subject').value=text(data.subject||'');
    $('nl_preheader').value=text(data.preheader||'');
    $('nl_from_name').value=text(data.from_name||$('nl_from_name').value||'');
    $('nl_from_email').value=data.from_email||$('nl_from_email').value||'';

    try{
      const savedBlocks=data.body_json
        ? JSON.parse(data.body_json)
        : [];

      blocks=hydrateBlocks(savedBlocks);
    }catch(e){
      blocks=[];
      console.error('Invalid newsletter body_json',e);
    }

    renderCanvas();
    $('nlModal').classList.add('open');
  }

  function copySubject(subject){
    const s=text(subject).trim();
    if(!s) return 'Copy of Newsletter';
    return /^copy of\s+/i.test(s) ? s : 'Copy of '+s;
  }

  function openDuplicateNewsletter(data){
    pendingDuplicate=clone(data);
    $('duplicateNewsletterSubject').textContent='Original: '+text(pendingDuplicate.subject||'Newsletter');
    $('duplicateNewsletterModal').classList.add('open');
  }

  function closeDuplicateNewsletter(){
    pendingDuplicate=null;
    closeModal('duplicateNewsletterModal');
  }

  function confirmDuplicateNewsletter(){
    if(!pendingDuplicate){
      closeDuplicateNewsletter();
      return;
    }

    const source=clone(pendingDuplicate);
    const sourceId=Number(source.id||0);

    if(sourceId<=0){
      showStatus('Invalid newsletter selected for duplication.',true);
      closeDuplicateNewsletter();
      return;
    }

    const modal=$('duplicateNewsletterModal');
    const duplicateButton=modal?.querySelector('.nl-btn-primary');

    if(duplicateButton){
      duplicateButton.disabled=true;
      duplicateButton.classList.add('is-loading');
      duplicateButton.innerHTML='<i class="fa fa-spinner fa-spin"></i> Duplicating...';
    }

    const fd=new FormData();
    fd.set('action','duplicate_newsletter');
    fd.set('newsletter_action','duplicate_newsletter');
    fd.set('id',String(sourceId));

    /*
     * Send the complete saved design as an additional duplication source.
     * This preserves section/background properties exactly as stored.
     */
    if(typeof source.body_json==='string' && source.body_json.trim()!==''){
      fd.set('source_body_json',source.body_json);
    }

    if(typeof source.body_html==='string' && source.body_html.trim()!==''){
      fd.set('source_body_html',source.body_html);
    }

    fetch(PROCESS_URL,{
      method:'POST',
      body:fd,
      headers:{
        'X-Requested-With':'XMLHttpRequest',
        'Accept':'application/json'
      }
    })
    .then(async response=>{
      const raw=await response.text();

      let json=null;

      try{
        json=JSON.parse(raw);
      }catch(error){
        throw new Error(
          raw
          ? 'The server returned an invalid duplication response.'
          : 'The server returned an empty duplication response.'
        );
      }

      if(!response.ok || !json.ok){
        throw new Error(json.message||'Newsletter duplication failed.');
      }

      return json;
    })
    .then(json=>{
      pendingDuplicate=null;
      closeModal('duplicateNewsletterModal');

      /*
       * The backend duplicate_newsletter action inserts the new row
       * immediately with:
       *   status = draft
       *   duplicated_from = source id
       *   subject = Copy of ...
       *
       * Reload the newsletter list so the newly saved draft is visible.
       */
      const url=new URL(window.location.href);
      url.searchParams.set('tab','newsletters');

      /*
       * Add a cache-buster so browsers/proxies do not show the previous
       * newsletter table after a successful insert.
       */
      url.searchParams.set('_nl',String(Date.now()));

      window.location.href=url.toString();
    })
    .catch(error=>{
      console.error('Newsletter duplicate error:',error);

      if(duplicateButton){
        duplicateButton.disabled=false;
        duplicateButton.classList.remove('is-loading');
        duplicateButton.innerHTML='<i class="fa fa-copy"></i> Duplicate';
      }

      /*
       * Keep the confirmation modal open and show the backend error
       * clearly instead of silently failing.
       */
      const subjectBox=$('duplicateNewsletterSubject');

      if(subjectBox){
        subjectBox.innerHTML=
          '<span style="color:#b91c1c">'
          + esc(error.message||'Newsletter duplication failed.')
          + '</span>';
      }
    });
  }

  function previewHtmlFromData(data){
    const saved=String(data?.body_html||'').trim();
    if(saved) return saved;

    let parsed=[];
    try{parsed=data?.body_json?hydrateBlocks(JSON.parse(data.body_json)):[];}
    catch(e){parsed=[];}

    if(!parsed.length) return '';

    const old=blocks;
    blocks=parsed;
    const html=buildEmailHtml(data.subject||'',data.preheader||'',data.from_name||'');
    blocks=old;
    return html;
  }

  function previewNewsletterData(data){
    const frame=$('previewFrame');
    if(!frame) return;

    $('previewTitle').textContent=text(data?.subject||'Newsletter Preview');
    setPreviewMode('desktop');
    $('previewModal').classList.add('open');

    const html=previewHtmlFromData(data);

    if(html){
      frame.removeAttribute('src');
      frame.srcdoc=html;
    }else if(data?.id){
      frame.removeAttribute('srcdoc');
      frame.src=PROCESS_URL+'?action=preview&id='+encodeURIComponent(data.id);
    }else{
      frame.srcdoc='<html><body style="font-family:Arial;padding:40px;text-align:center;color:#64748b"><h2>No preview available</h2></body></html>';
    }
  }

  function previewNewsletter(id){
    const frame=$('previewFrame');
    if(!frame) return;
    $('previewTitle').textContent='Newsletter Preview';
    setPreviewMode('desktop');
    $('previewModal').classList.add('open');
    frame.removeAttribute('srcdoc');
    frame.src=PROCESS_URL+'?action=preview&id='+encodeURIComponent(id);
  }

  function setPreviewMode(mode){
    const mobile=mode==='mobile';
    $('previewFrame')?.classList.toggle('preview-mobile',mobile);
    $('previewDesktopBtn')?.classList.toggle('active',!mobile);
    $('previewMobileBtn')?.classList.toggle('active',mobile);
  }

  function closePreview(){
    closeModal('previewModal');
    const f=$('previewFrame');
    if(f){
      f.removeAttribute('src');
      f.srcdoc='';
    }
  }

  function switchRcpt(type,btn,prefix){
    document.querySelectorAll(`[id^="${prefix}_rcpt_"]`).forEach(p=>p.classList.remove('active'));
    document.querySelectorAll('.rcpt-tab').forEach(b=>{
      if(b.closest(`#${prefix}Modal`) || (prefix==='nl' && b.closest('#nlModal'))) b.classList.remove('active');
    });
    btn.classList.add('active');
    $(`${prefix}_rcpt_${type}`)?.classList.add('active');
    if($(`${prefix}_recipient_type`)) $(`${prefix}_recipient_type`).value=type;
  }

  function selectAllStartups(prefix){
    document.querySelectorAll('.'+prefix+'-startup-chk').forEach(c=>c.checked=true);
  }

  function handleEmailTag(e,prefix){
    if(e.key==='Enter' || e.key===','){
      e.preventDefault();
      addEmailTag(e.target.value.trim(),prefix);
    }
  }

  function addEmailTag(email,prefix){
    if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return;

    const box=$(prefix==='nl'?'nlEmailTagsBox':'pdfEmailTagsBox');
    const input=$(prefix==='nl'?'nlEtInput':'pdfEtInput');
    if(!box||!input) return;

    const duplicate=[...box.querySelectorAll('.etag')].some(t=>String(t.dataset.email).toLowerCase()===email.toLowerCase());
    if(duplicate){
      input.value='';
      return;
    }

    const tag=document.createElement('span');
    tag.className='etag';
    tag.dataset.email=email;
    tag.innerHTML=`${esc(email)}<button type="button">&times;</button>`;
    tag.querySelector('button').onclick=()=>{
      tag.remove();
      updateManualCount(prefix);
    };
    box.insertBefore(tag,input);
    input.value='';
    updateManualCount(prefix);
  }

  function updateManualCount(prefix){
    const boxId=prefix==='nl'?'nlEmailTagsBox':'pdfEmailTagsBox';
    const countId=prefix==='nl'?'nlManualCount':'pdfManualCount';
    const n=document.querySelectorAll('#'+boxId+' .etag').length;
    if($(countId)) $(countId).textContent=n+' email'+(n===1?'':'s');
  }

  function parseEmailFile(input,prefix){
    const file=input.files?.[0];
    if(!file) return;

    const reader=new FileReader();
    reader.onload=e=>{
      const found=[...new Set((e.target.result.match(/[^\s,;]+@[^\s,;]+\.[^\s,;]+/g)||[]))];
      if(prefix==='nl') uploadedEmailsNL=found;
      else uploadedEmailsPDF=found;
      const info=$(prefix==='nl'?'nlUploadedEmailsInfo':'pdfUploadedEmailsInfo');
      if(info) info.textContent=found.length+' valid email(s) found.';
    };
    reader.readAsText(file);
  }

  function getRecipients(prefix){
    const type=$(`${prefix}_recipient_type`)?.value||'startups';
    let emails=[];

    if(type==='startups'){
      emails=[...document.querySelectorAll('.'+prefix+'-startup-chk:checked')].map(c=>c.value);
      if(!emails.length){
        showStatus('Select at least one startup recipient.',true);
        return false;
      }
    }

    if(type==='saved'){
      emails=[...document.querySelectorAll('.'+prefix+'-saved-chk:checked')].map(c=>c.value);
      if(!emails.length){
        showStatus('Select at least one saved recipient.',true);
        return false;
      }
    }

    if(type==='manual'){
      const boxId=prefix==='nl'?'nlEmailTagsBox':'pdfEmailTagsBox';
      emails=[...document.querySelectorAll('#'+boxId+' .etag')].map(t=>t.dataset.email);
      if(!emails.length){
        showStatus('Add at least one manual email.',true);
        return false;
      }
    }

    if(type==='upload'){
      emails=prefix==='nl'?uploadedEmailsNL:uploadedEmailsPDF;
      if(!emails.length){
        showStatus('Upload a CSV/TXT containing valid emails.',true);
        return false;
      }
    }

    return emails;
  }

  function handlePdfPick(input){
    const file=input.files?.[0];
    if(!file) return;

    if(!file.name.toLowerCase().endsWith('.pdf')){
      input.value='';
      return;
    }

    $('pdfFileName').textContent=file.name;
    $('pdfFileSize').textContent=formatBytes(file.size);
    $('pdfDropZone').style.display='none';
    $('pdfFileCard').style.display='flex';
    $('pdfPreviewPane').style.display='';
    $('pdfPreviewFrame').src=URL.createObjectURL(file);
  }

  function removePdf(){
    $('pdfFileInput').value='';
    $('pdfDropZone').style.display='';
    $('pdfFileCard').style.display='none';
    $('pdfPreviewPane').style.display='none';
    $('pdfPreviewFrame').src='';
  }

  function formatBytes(bytes){
    if(bytes<1024) return bytes+' B';
    if(bytes<1048576) return (bytes/1024).toFixed(1)+' KB';
    return (bytes/1048576).toFixed(2)+' MB';
  }

  function preparePdfSubmit(event){
    const submitter=event.submitter||document.activeElement;
    const draft=submitter?.value==='draft';

    if(!draft && !$('pdfFileInput')?.files?.[0]) return false;

    /*
     * Saving a PDF newsletter as a draft does not require recipients.
     */
    if(draft){
      if($('pdf_recipient_emails')){
        $('pdf_recipient_emails').value='[]';
      }
      return true;
    }

    const emails=getRecipients('pdf');
    if(emails===false) return false;

    $('pdf_recipient_emails').value=JSON.stringify(emails);
    return true;
  }

  function initPdfDrop(){
    const dz=$('pdfDropZone');
    if(!dz || dz.dataset.bound==='1') return;
    dz.dataset.bound='1';

    ['dragenter','dragover'].forEach(ev=>dz.addEventListener(ev,e=>{
      e.preventDefault();
      dz.classList.add('drag-over');
    }));

    ['dragleave','drop'].forEach(ev=>dz.addEventListener(ev,e=>{
      e.preventDefault();
      dz.classList.remove('drag-over');
    }));

    dz.addEventListener('drop',e=>{
      const file=e.dataTransfer.files?.[0];
      if(!file || typeof DataTransfer==='undefined') return;
      const dt=new DataTransfer();
      dt.items.add(file);
      $('pdfFileInput').files=dt.files;
      handlePdfPick($('pdfFileInput'));
    });
  }

  function switchTab(id,btn){
    document.querySelectorAll('.nl-tab-content').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.nl-tab').forEach(t=>t.classList.remove('active'));
    $('tc_'+id)?.classList.add('active');
    btn.classList.add('active');

    const url=new URL(location.href);
    url.searchParams.set('tab',id);
    history.replaceState(null,'',url);
  }

  function confirmSend(id,subject){
    if(!confirm(`Send "${text(subject)}" to its selected recipients now?`)) return;

    const f=document.createElement('form');
    f.method='POST';
    f.action=PROCESS_URL;
    f.innerHTML=`<input name="action" value="send_now"><input name="id" value="${Number(id)||0}">`;
    document.body.appendChild(f);
    f.submit();
  }

  function confirmDeleteNewsletter(form){
    pendingDeleteForm=form;
    $('newsletterDeleteModal').classList.add('open');
    return false;
  }

  function cancelDeleteNewsletter(){
    pendingDeleteForm=null;
    closeModal('newsletterDeleteModal');
  }

  function executeDeleteNewsletter(){
    if(!pendingDeleteForm) return cancelDeleteNewsletter();

    const form=pendingDeleteForm;
    const id=Number(
      form.querySelector('input[name="id"]')?.value || 0
    );

    if(id<=0){
      cancelDeleteNewsletter();
      alert('Invalid newsletter selected.');
      return;
    }

    const modal=$('newsletterDeleteModal');
    const deleteBtn=modal?.querySelector('.nl-btn-danger');

    if(deleteBtn){
      deleteBtn.disabled=true;
      deleteBtn.innerHTML='<i class="fa fa-spinner fa-spin"></i> Deleting...';
    }

    const fd=new FormData();
    fd.set('action','delete');
    fd.set('newsletter_action','delete');
    fd.set('id',String(id));

    fetch(window.location.pathname,{
      method:'POST',
      body:fd,
      headers:{
        'X-Requested-With':'XMLHttpRequest',
        'Accept':'application/json'
      }
    })
    .then(async response=>{
      const raw=await response.text();
      let data=null;

      try{
        data=JSON.parse(raw);
      }catch(error){
        const clean=String(raw||'')
          .replace(/<[^>]*>/g,' ')
          .replace(/\s+/g,' ')
          .trim();

        throw new Error(
          clean
            ? clean.substring(0,250)
            : `Delete failed (HTTP ${response.status}).`
        );
      }

      if(!response.ok || !data.ok){
        throw new Error(
          data?.message
          || `Delete failed (HTTP ${response.status}).`
        );
      }

      return data;
    })
    .then(()=>{
      pendingDeleteForm=null;
      closeModal('newsletterDeleteModal');

      const url=new URL(window.location.href);
      url.searchParams.set('tab','newsletters');
      url.searchParams.set('_nl',String(Date.now()));
      window.location.href=url.toString();
    })
    .catch(error=>{
      console.error('Newsletter delete error:',error);

      if(deleteBtn){
        deleteBtn.disabled=false;
        deleteBtn.innerHTML='<i class="fa fa-trash"></i> Delete';
      }

      alert(
        error.message
        || 'Newsletter could not be deleted.'
      );
    });
  }

  function setBgType(type){
    ['color','image','video'].forEach(t=>$('bgt-'+t)?.classList.toggle('active',t===type));
    if($('heroColorSection')) $('heroColorSection').style.display=type==='color'?'':'none';
    if($('heroImageSection')) $('heroImageSection').style.display=type==='image'?'':'none';
    if($('heroVideoSection')) $('heroVideoSection').style.display=type==='video'?'':'none';
  }

  function applyHeroBgColor(hex){
    if($('heroPreview')){
      $('heroPreview').style.background=hex;
      $('heroPreview').style.backgroundImage='';
    }
    if($('heroBgColorPicker')) $('heroBgColorPicker').value=hex;
    if($('heroBgColorText')) $('heroBgColorText').value=hex;
  }

  function syncHeroBgText(){
    const v=$('heroBgColorText')?.value||'';
    if(/^#[0-9A-Fa-f]{6}$/.test(v)){
      $('heroBgColorPicker').value=v;
      $('heroPreview').style.background=v;
    }
  }

  function applyHeroOverlayColor(hex){
    if($('heroOverlayEl')) $('heroOverlayEl').style.background=hex;
    if($('heroOverlayColorText')) $('heroOverlayColorText').value=hex;
  }

  function syncHeroOverlayText(){
    const v=$('heroOverlayColorText')?.value||'';
    if(/^#[0-9A-Fa-f]{6}$/.test(v)){
      $('heroOverlayColorPicker').value=v;
      $('heroOverlayEl').style.background=v;
    }
  }

  function syncOpacity(v){
    if($('heroOpacityNum')) $('heroOpacityNum').value=v;
    if($('opacityLabel')) $('opacityLabel').textContent=v;
    updateOverlayPreview();
  }

  function syncOpacityNum(v){ syncOpacity(v); }

  function updateOverlayPreview(){
    const on=$('heroOverlayCb')?.checked;
    const op=Number($('heroOpacityRange')?.value||0)/100;
    if($('heroOverlayEl')) $('heroOverlayEl').style.opacity=on?op:0;
  }

  function previewHeroImg(input){
    const file=input.files?.[0];
    if(!file) return;

    const r=new FileReader();
    r.onload=e=>{
      $('heroPreview').style.background='';
      $('heroPreview').style.backgroundImage=`url("${e.target.result}")`;
      $('heroPreview').style.backgroundSize='cover';
      $('heroPreview').style.backgroundPosition='center';
    };
    r.readAsDataURL(file);
  }

  function previewHeroVid(){}
  function clearImgPreview(){}
  function clearVidPreview(){}

  function removeHeroAsset(type){
    const f=document.createElement('form');
    f.method='POST';
    f.action=PROCESS_URL;
    f.innerHTML=`<input name="action" value="remove_hero_${esc(type)}">`;
    document.body.appendChild(f);
    f.submit();
  }

  document.addEventListener('submit',e=>{
    activeSubmitter=e.submitter||document.activeElement;
  });

  document.addEventListener('click',e=>{
    if(!e.target.closest('.nl-block') && !e.target.closest('#nlSettings') && !e.target.closest('.nl-palette-btn')){
      selectedId=null;
      settingsEmpty();
      document.querySelectorAll('.nl-block').forEach(el=>el.classList.remove('selected'));
    }
  });

  document.addEventListener('keydown',e=>{
    if(e.key!=='Escape') return;
    document.querySelectorAll('.modal-overlay.open').forEach(m=>m.classList.remove('open'));
    pendingDuplicate=null;
    pendingDeleteForm=null;
  });

  document.addEventListener('DOMContentLoaded',()=>{
    renderCanvas();
    initPdfDrop();

    document.querySelectorAll('.modal-overlay').forEach(m=>{
      m.addEventListener('click',e=>{
        if(e.target===m){
          m.classList.remove('open');
          if(m.id==='duplicateNewsletterModal') pendingDuplicate=null;
          if(m.id==='newsletterDeleteModal') pendingDeleteForm=null;
        }
      });
    });
  });

  return {
    closeModal,
    openBuilder,closeBuilder,openPdfModal,closePdfModal,openSubsModal,
    loadHiveTemplate,clearCanvas,paletteClick,addContentLayout,addBlock,
    duplicateBlock,deleteBlock,editNewsletter,
    openDuplicateNewsletter,closeDuplicateNewsletter,confirmDuplicateNewsletter,
    previewNewsletter,previewNewsletterData,setPreviewMode,closePreview,
    prepareNLSubmit,handleBlockImageUpload,handleBackgroundUpload,setBlockBackgroundType,setSectionBackgroundType,removeBlockBackgroundImage,removeSectionBackgroundImage,
    switchRcpt,selectAllStartups,handleEmailTag,updateManualCount,parseEmailFile,
    handlePdfPick,removePdf,preparePdfSubmit,
    switchTab,confirmSend,
    confirmDeleteNewsletter,cancelDeleteNewsletter,executeDeleteNewsletter,
    setBgType,applyHeroBgColor,syncHeroBgText,applyHeroOverlayColor,
    syncHeroOverlayText,syncOpacity,syncOpacityNum,updateOverlayPreview,
    previewHeroImg,previewHeroVid,clearImgPreview,clearVidPreview,removeHeroAsset
  };
})();
var NL = window.NL;