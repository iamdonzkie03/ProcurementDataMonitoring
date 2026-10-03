document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('[data-sidebar-accordion]').forEach(button=>{
    button.addEventListener('click',()=>{
      const group=button.closest('.sidebar-group');
      const submenu=document.getElementById(button.getAttribute('aria-controls'));
      if(!group||!submenu)return;
      const open=group.classList.toggle('is-open');
      button.setAttribute('aria-expanded',open?'true':'false');
      submenu.hidden=!open;
    });
  });
  document.querySelectorAll('[data-confirm]').forEach(el=>el.addEventListener('click',e=>{if(!confirm(el.dataset.confirm))e.preventDefault()}));
  document.querySelectorAll('[data-number]').forEach(el=>el.addEventListener('input',()=>{if(parseFloat(el.value)<0)el.value=0;}));
});
