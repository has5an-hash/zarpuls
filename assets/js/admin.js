(function($){
  'use strict';

  function zgNumber(n){
    n = Math.round(Number(n) || 0);
    return n.toLocaleString('fa-IR');
  }
  function zgRelTime(ts){
    if(!ts){return '—';}
    var diff = Math.max(0, Math.floor(Date.now()/1000) - ts);
    if(diff < 60){return 'لحظاتی پیش';}
    if(diff < 3600){return Math.floor(diff/60)+' دقیقه قبل';}
    if(diff < 86400){return Math.floor(diff/3600)+' ساعت قبل';}
    return Math.floor(diff/86400)+' روز قبل';
  }
  function zgKeyLabel(k){
    return {
      '18k': 'طلای ۱۸ عیار',
      '24k': 'طلای ۲۴ عیار',
      '24k_999': 'طلای ۲۴ عیار (۹۹۹)',
      'mazaneh': 'مظنه طلا (۷۰۵)',
      'mazaneh_705': 'مظنه طلا (۷۰۵)',
      'coin': 'سکه امامی',
      'nim': 'نیم‌سکه',
      'rob': 'ربع‌سکه'
    }[k] || k;
  }
  function zgTime(ts){
    if(!ts){return '—';}
    return new Date(Number(ts)*1000).toLocaleTimeString('fa-IR',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
  }
  function zgDateTime(dt){
    if(!dt){return '—';}
    if(typeof dt === 'number'){ return new Date(dt*1000).toLocaleDateString('fa-IR') + ' — ' + new Date(dt*1000).toLocaleTimeString('fa-IR',{hour:'2-digit',minute:'2-digit',second:'2-digit'}); }
    var d = new Date(String(dt).replace(' ','T')+'Z');
    if (isNaN(d.getTime())) { return String(dt); }
    return d.toLocaleDateString('fa-IR') + ' — ' + d.toLocaleTimeString('fa-IR',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
  }
  function zgClockNow(){
    return new Date().toLocaleTimeString('fa-IR',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
  }
  /** فلش سبز/قرمز با فلش ▲▼ هنگام تغییر قیمت */
  function zgFlash($el, val){
    if(!$el || !$el.length){return;}
    var prev = $el.data('zgprev');
    var dir = '';
    if (prev !== undefined && prev !== null && prev !== '' && Number(prev) !== Number(val)) {
      dir = Number(val) > Number(prev) ? 'up' : 'down';
    }
    $el.data('zgprev', val);
    $el.find('.zg-arrow').remove();
    $el.removeClass('zg-flash-up zg-flash-down');
    if (dir) {
      void $el[0].offsetWidth;
      $el.addClass('zg-flash-' + dir);
      $el.append('<span class="zg-arrow ' + dir + '">' + (dir==='up' ? '▲' : '▼') + '</span>');
      window.setTimeout(function(){ $el.removeClass('zg-flash-up zg-flash-down'); }, 1600);
    }
  }
  /** جایگزینی محتوا با افکت محو + فلش */
  function zgSwap($el, html){
    if(!$el || !$el.length){return;}
    if ($el.data('zg-hash') === html) { return; }
    $el.data('zg-hash', html);
    $el.stop(true,true).css({opacity:1});
    $el.animate({opacity:0}, 150, function(){
      $(this).html(html).addClass('zg-swap-in').animate({opacity:1}, 250, function(){ $(this).removeClass('zg-swap-in'); });
    });
  }
  function zgBadge(status, text){
    var cls = status === 'ok' ? 'is-ok' : (status === 'error' ? 'is-err' : (status === 'warning' ? 'is-warn' : 'is-muted'));
    return '<span class="zg-trace-badge '+cls+'">'+text+'</span>';
  }
  function zgElapsed(ts){
    var diff = Math.max(0, Math.floor(Date.now()/1000) - ts);
    if (diff < 60) { return 'همین الان (' + diff + ' ثانیه قبل)'; }
    var m = Math.floor(diff/60), s = diff%60;
    return m + ' دقیقه ' + s + ' ثانیه قبل';
  }
  function zgCountdown(ts){
    var left = Math.max(0, ts - Math.floor(Date.now()/1000));
    var m = Math.floor(left/60), s = left%60;
    return String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
  }
  function zgCronTick(){
    var $c = $('[data-cron-live]');
    if (!$c.length) { return; }
    var last = Number($c.data('last-ts'))||0, next = Number($c.data('next-ts'))||0;
    if (last) { $('[data-last-run]').text(zgElapsed(last)); }
    if (next) {
      var $n = $('[data-next-run]');
      $n.text(zgCountdown(next));
      $c.toggleClass('is-near', (next - Math.floor(Date.now()/1000)) <= 10);
    }
  }

  function zgPair(v){
    if (v && typeof v === 'object') {
      var buy = Number(v.buy)||0, sell = Number(v.sell)||0, mid = Number(v.mid)||0;
      return { buy: buy, sell: sell, mid: mid };
    }
    var n = Number(v)||0;
    return { buy: 0, sell: 0, mid: n };
  }
  function zgPairText(v){
    var p = zgPair(v);
    if (p.buy > 0 && p.sell > 0 && Math.abs(p.buy - p.sell) >= 1) { return 'خرید ' + zgNumber(p.buy) + ' — فروش ' + zgNumber(p.sell); }
    if (p.buy > 0) { return zgNumber(p.buy); }
    if (p.sell > 0) { return zgNumber(p.sell); }
    if (p.mid > 0) { return zgNumber(p.mid); }
    return '—';
  }

  $(function(){
    if (window.ZGAdmin) {
      var cronBusy=false;
      var cronBeat=function(){
        if(cronBusy){return;} cronBusy=true;
        $.post(ZGAdmin.ajaxUrl,{action:'zg_cron_heartbeat',nonce:ZGAdmin.cronNonce}).done(function(r){
          var el=$('[data-cron-status]');
          if(!el.length){return;}
          if(r && r.success){
            if(r.data && r.data.status==='updated'){
              el.text('بروزرسانی خودکار فعال • همین الان انجام شد'); $('[data-cron-status]').addClass('is-updated');
              var $c = $('[data-cron-live]');
              if (r.data.updated_at) { $c.data('last-ts', Number(r.data.updated_at)); }
            }
            else if(r.data && r.data.status==='fresh'){ el.text('بروزرسانی خودکار فعال • زمان‌بندی سالم'); }
            else if(r.data && r.data.status==='busy'){ el.text('بروزرسانی خودکار در حال اجراست…'); }
            else { el.text('بروزرسانی خودکار فعال'); }
          }else{ el.text('بروزرسانی خودکار: خطا؛ در گزارش‌ها بررسی کنید'); }
        }).always(function(){cronBusy=false;});
      };
      cronBeat();
      window.setInterval(cronBeat,60000);
      zgCronTick();
      window.setInterval(zgCronTick,1000);
    }

    $(document).on('click','.zg-token',function(){
      var field=$('#zg_formula_expression'), token=' '+$(this).data('token')+' ', el=field.get(0);
      if(!el){return;} var start=el.selectionStart||0,end=el.selectionEnd||0,value=field.val();
      field.val(value.substring(0,start)+token+value.substring(end));
      el.focus(); el.selectionStart=el.selectionEnd=start+token.length;
    });
    $(document).on('click','.zg-preview-product',function(){
      var button=$(this),result=button.siblings('.zg-preview-result'),data={action:'zg_preview_price',nonce:ZGAdmin.nonce,product_id:button.data('product-id')};
      ['enabled','product_type','weight','karat','stone','wage','wage_per_gram','wage_percent','profit_percent','profit_fixed','extra','packing','shipping','insurance','manufacturing','tax_percent','formula_id'].forEach(function(key){
        var input=$('#_zg_'+key); data['_zg_'+key]=input.attr('type')==='checkbox'?(input.is(':checked')?'yes':''):input.val();
      });
      button.prop('disabled',true);result.removeClass('is-error').text(ZGAdmin.calculating);
      $.post(ZGAdmin.ajaxUrl,data).done(function(response){
        if(response.success){result.text(response.data.formatted+' — '+response.data.formula);}else{result.addClass('is-error').text(response.data&&response.data.message?response.data.message:ZGAdmin.error);}
      }).fail(function(xhr){var msg=xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message?xhr.responseJSON.data.message:ZGAdmin.error;result.addClass('is-error').text(msg);}).always(function(){button.prop('disabled',false);});
    });

    /* ---------- دکمه کپی ---------- */
    $(document).on('click','.zg-copy-btn',function(){
      var text=$(this).data('copy-target')||''; var btn=$(this);
      if(!text){return;}
      if(navigator.clipboard && navigator.clipboard.writeText){
        navigator.clipboard.writeText(text).then(function(){ btn.text('کپی شد ✓'); setTimeout(function(){btn.text('کپی');},1500); }).catch(function(){ fallbackCopy(btn,text); });
      } else { fallbackCopy(btn,text); }
    });
    function fallbackCopy(btn,text){
      var t=$('<textarea>').val(text).css({position:'absolute',left:'-9999px'}).appendTo('body');
      t.select(); try{ document.execCommand('copy'); btn.text('کپی شد ✓'); }catch(e){ btn.text('کپی نشد'); }
      t.remove(); setTimeout(function(){btn.text('کپی');},1500);
    }

    /* ---------- تنظیم خودکار wp-config.php (Cron واقعی سرور) ---------- */
    $(document).on('click','#zg-configure-cron',function(){
      var btn=$(this), res=$('[data-cron-config-result]');
      btn.prop('disabled',true); res.removeClass('is-error').text('در حال تنظیم…');
      $.post(ZGAdmin.ajaxUrl,{action:'zg_configure_wp_cron',nonce:ZGAdmin.nonce}).done(function(r){
        if(r && r.success){ res.removeClass('is-error').text(r.data.message || 'انجام شد.'); }
        else { res.addClass('is-error').text('ناموفق: ' + (r && r.data && r.data.message ? r.data.message : 'خطای نامشخص')); }
      }).fail(function(xhr){
        var msg=xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message?xhr.responseJSON.data.message:'خطا در ارتباط با سرور';
        res.addClass('is-error').text('ناموفق: '+msg);
      }).always(function(){btn.prop('disabled',false);});
    });

    /* ---------- داشبورد: وضعیت لحظه‌ای و روند محاسبه ---------- */
    var $trace = $('[data-dash-trace]');
    if ($trace.length) {
      var dashLoading = false;
      var renderTrace = function(trace){
        if(!trace || !trace.steps || !trace.steps.length){
          $trace.html('<p class="description">قیمت‌ها به‌صورت خودکار و لحظه‌ای محاسبه می‌شوند؛ این بخش چند ثانیه دیگر به‌روز می‌شود.</p>');
          return;
        }
        var modeName = ({auto:'محاسبه هوشمند و خودکار', manual:'دستی (API مستقیم)'}[trace.mode]||'محاسبه هوشمند');
        var html = '<div class="zg-trace-head"><span>آغاز: ' + zgDateTime(trace.started_at) + '</span><span>حالت: ' + modeName + '</span></div>';
        html += '<ol class="zg-trace-list">';
        var idx = 0;
        $.each(trace.steps, function(_, step){
          var status = step.status;
          var t = '<span class="zg-trace-time">' + zgDateTime(step.at) + '</span>';
          var dly = ' style="animation-delay:' + (idx++ * 0.045) + 's"';
          if(status === 'disabled'){ html += '<li class="zg-trace-step zg-item-in is-muted"'+dly+'>'+zgBadge('muted','غیرفعال')+'<div class="zg-trace-body"><b>'+$('<div>').text(step.name||step.source).html()+'</b>'+t+'</div></li>'; return; }
          var badgeTxt = status === 'ok' ? 'موفق' : (status === 'warning' ? 'پشتیبان/کش' : 'خطا');
          html += '<li class="zg-trace-step zg-item-in '+(status==='error'?'is-err':(status==='ok'?'is-ok':'is-warn'))+'"'+dly+'>'+zgBadge(status, badgeTxt)+'<div class="zg-trace-body"><b>'+$('<div>').text(step.name||step.source).html()+'</b>'+t;
          if(status === 'error'){ html += '<small class="zg-trace-err">'+$('<div>').text(step.error||'').html()+'</small>'; }
          else if(step.values && typeof step.values === 'object'){
            var vals = [];
            $.each(step.values, function(k,v){
              if(k && k.charAt(0)==='_' || k === 'ounce_usd' || k === 'dollar_toman'){return;}
              vals.push(zgKeyLabel(k)+': '+zgNumber(v));
            });
            if(vals.length){ html += '<small>نرخ‌های محاسبه‌شده: '+$('<div>').text(vals.join(' • ')).html()+'</small>'; }
          }
          html += '</div></li>';
        });
        html += '</ol>';
        if(trace.final_prices && typeof trace.final_prices === 'object'){
          var fp = [];
          $.each(trace.final_prices, function(k,v){
            if(typeof v === 'number' && ['18k','24k','mazaneh','coin','nim','rob'].indexOf(k) !== -1){
              fp.push(zgKeyLabel(k)+': '+zgNumber(v));
            }
          });
          if(fp.length){ html += '<p class="zg-trace-final">نرخ‌های فعال: <strong>'+$('<div>').text(fp.join(' • ')).html()+'</strong> <span class="zg-trace-time">'+zgDateTime(trace.ended_at)+'</span></p>'; }
        }
        zgSwap($trace, html);
      };

      var loadDashboard = function(){
        if(dashLoading){return;} dashLoading=true;
        $.post(ZGAdmin.ajaxUrl, {action:'zg_dashboard_live', nonce:ZGAdmin.nonce}).done(function(r){
          if(!r || !r.success){ return; }
          var d = r.data || {}, cur = d.currency || '';
          $.each({ '18k':d.final&&d.final.buy, '24k':d.final&&d.final.buy_24k }, function(key, val){
            var el = $('[data-buy="'+key+'"]'); if(el.length){ var v = val>0?Number(val):0; el.text(v>0 ? zgNumber(v) : '—'); zgFlash(el, v); }
          });
          $.each({ '18k':d.final&&d.final.sell, '24k':d.final&&d.final.sell_24k }, function(key, val){
            var el = $('[data-sell="'+key+'"]'); if(el.length){ var v = val>0?Number(val):0; el.text(v>0 ? zgNumber(v) : '—'); zgFlash(el, v); }
          });
          $('[data-currency]').text(cur);
          if(d.updated_at){ $('[data-last-update]').text(zgRelTime(d.updated_at)); }
          var modeLabel = { auto:'محاسبه هوشمند و خودکار (پیشنهادی)', manual:'دستی (API مستقیم)' }[d.mode] || 'محاسبه هوشمند';
          $('[data-dash-mode]').text('حالت: ' + modeLabel);
          $('[data-dash-updated]').text('ساعت اکنون: '+zgClockNow());
          $('[data-dash-indicator]').text('زنده • هر ۵ ثانیه به‌روز می‌شود');
          renderTrace(d.trace);
          if (d.events) {
            var ev = '<table class="widefat striped zg-events"><thead><tr><th>زمان</th><th>نوع</th><th>سطح</th><th>پیام</th></tr></thead><tbody>';
            if (!d.events.length) { ev += '<tr><td colspan="4">هنوز رویدادی ثبت نشده است.</td></tr>'; }
            $.each(d.events, function(_, e){ ev += '<tr><td>'+zgDateTime(e.created_at)+'</td><td>'+$('<div>').text(e.event_type).html()+'</td><td><span class="zg-level '+$('<div>').text(e.level).html()+'">'+$('<div>').text(e.level).html()+'</span></td><td>'+$('<div>').text(e.message).html()+'</td></tr>'; });
            ev += '</tbody></table>';
            zgSwap($('[data-dash-events]'), ev);
            $('[data-dash-log-indicator]').text('هر ۵ ثانیه به‌روز می‌شود');
          }
        }).fail(function(){ $('[data-dash-indicator]').text('اتصال برقرار نشد؛ دوباره تلاش می‌شود'); }).always(function(){ dashLoading=false; });
      };
      loadDashboard();
      window.setInterval(loadDashboard, 5000);
      window.setInterval(function(){ var el=$('[data-dash-updated]'); if(el.length){ el.text('ساعت اکنون: '+zgClockNow()); } }, 1000);
    }

    /* ---------- صفحه منابع قیمت: تغییر حالت، تست موتور فرمول و تست API مستقیم ---------- */
    var $sourcesForm = $('#zg-sources-form');
    if ($sourcesForm.length) {
      var togglePricingPanels = function(){
        var mode = $sourcesForm.find('input[data-mode-radio]:checked').val() || 'auto';
        $sourcesForm.find('[data-mode-panel]').hide();
        $sourcesForm.find('[data-mode-panel="'+mode+'"]').show();
        $sourcesForm.find('.zg-mode-option').removeClass('is-active');
        $sourcesForm.find('.zg-mode-option:has(input[data-mode-radio]:checked)').addClass('is-active');
      };
      togglePricingPanels();
      $sourcesForm.on('change', 'input[data-mode-radio]', togglePricingPanels);

      var toggleDirectFormat = function(){
        var fmt = $sourcesForm.find('.zg-direct-format').val() || 'json';
        $sourcesForm.find('.zg-direct-json-fields').toggle(fmt === 'json');
        $sourcesForm.find('.zg-direct-text-hint').toggle(fmt === 'text');
      };
      toggleDirectFormat();
      $sourcesForm.on('change', '.zg-direct-format', toggleDirectFormat);

      // دکمه تست و بازخوانی زنده موتور فرمول خودکار
      $(document).on('click', '[data-test-auto-engine]', function(){
        var $btn = $(this), $res = $('[data-auto-test-result]');
        $btn.prop('disabled', true);
        $res.removeClass('is-error').text('در حال محاسبه نرخ‌ها…');
        $.post(ZGAdmin.ajaxUrl, { action: 'zg_test_auto_engine', nonce: ZGAdmin.nonce }).done(function(r){
          if (r && r.success && r.data && r.data.data) {
            var d = r.data.data;
            if (d.gold_18k_buy || d.gold_18k) {
              var txt18 = zgNumber(d.gold_18k_buy || d.gold_18k) + (d.gold_18k_sell ? ' / ' + zgNumber(d.gold_18k_sell) : '') + ' تومان';
              $('[data-auto-18k]').text(txt18);
              zgFlash($('[data-auto-18k]'), d.gold_18k_buy || d.gold_18k);
            }
            var g24b = d.gold_24k_buy || d.gold_24k_pure || d.gold_24k;
            var g24s = d.gold_24k_sell || 0;
            if (g24b) {
              var txt24 = zgNumber(g24b) + (g24s ? ' / ' + zgNumber(g24s) : '') + ' تومان';
              $('[data-auto-24k]').text(txt24);
              zgFlash($('[data-auto-24k]'), g24b);
            }
            if (d.mazaneh_705_buy || d.mazaneh_705) {
              var txtMaz = zgNumber(d.mazaneh_705_buy || d.mazaneh_705) + (d.mazaneh_705_sell ? ' / ' + zgNumber(d.mazaneh_705_sell) : '') + ' تومان';
              $('[data-auto-mazaneh]').text(txtMaz);
              zgFlash($('[data-auto-mazaneh]'), d.mazaneh_705_buy || d.mazaneh_705);
            }
            if (d.emami_coin_buy || d.emami_coin) {
              var txtEmami = zgNumber(d.emami_coin_buy || d.emami_coin) + (d.emami_coin_sell ? ' / ' + zgNumber(d.emami_coin_sell) : '') + ' تومان';
              $('[data-auto-emami-coin]').text(txtEmami);
            }
            if (d.half_coin_buy || d.half_coin) {
              var txtHalf = zgNumber(d.half_coin_buy || d.half_coin) + (d.half_coin_sell ? ' / ' + zgNumber(d.half_coin_sell) : '') + ' تومان';
              $('[data-auto-half-coin]').text(txtHalf);
            }
            if (d.quarter_coin_buy || d.quarter_coin) {
              var txtQuarter = zgNumber(d.quarter_coin_buy || d.quarter_coin) + (d.quarter_coin_sell ? ' / ' + zgNumber(d.quarter_coin_sell) : '') + ' تومان';
              $('[data-auto-quarter-coin]').text(txtQuarter);
            }
            if (d.emami_intrinsic) {
              $('[data-auto-emami-intrinsic]').text(zgNumber(d.emami_intrinsic) + ' تومان');
            }
            if (d.half_coin_intrinsic && d.quarter_coin_intrinsic) {
              $('[data-auto-sub-intrinsic]').text(zgNumber(d.half_coin_intrinsic) + ' / ' + zgNumber(d.quarter_coin_intrinsic) + ' تومان');
            }
            $res.removeClass('is-error').text('محاسبه موفق ✓ ' + (r.data.message || ''));
          } else {
            $res.addClass('is-error').text('ناموفق: ' + (r && r.data && r.data.message ? r.data.message : 'خطای نامشخص'));
          }
        }).fail(function(xhr){
          var msg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : 'خطا در ارتباط با سرور';
          $res.addClass('is-error').text('ناموفق: ' + msg);
        }).always(function(){
          $btn.prop('disabled', false);
        });
      });

      // بارگذاری دوره‌ای وضعیت نرخ‌های زنده
      var loadLiveStatus = function(){
        var $indicator = $('[data-live-indicator]');
        var $autoIndicator = $('[data-auto-indicator]');
        $.post(ZGAdmin.ajaxUrl, { action: 'zg_sources_status', nonce: ZGAdmin.nonce }).done(function(r){
          if (!r || !r.success) { return; }
          var currency = r.data.currency_label || '';
          if (r.data.auto) {
            var a = r.data.auto;
            if (a.gold_18k_buy || a.gold_18k) {
              $('[data-auto-18k]').text(zgNumber(a.gold_18k_buy || a.gold_18k) + (a.gold_18k_sell ? ' / ' + zgNumber(a.gold_18k_sell) : '') + ' تومان');
            }
            var g24ba = a.gold_24k_buy || a.gold_24k_pure || a.gold_24k;
            var g24sa = a.gold_24k_sell || 0;
            if (g24ba) {
              $('[data-auto-24k]').text(zgNumber(g24ba) + (g24sa ? ' / ' + zgNumber(g24sa) : '') + ' تومان');
            }
            if (a.mazaneh_705_buy || a.mazaneh_705) {
              $('[data-auto-mazaneh]').text(zgNumber(a.mazaneh_705_buy || a.mazaneh_705) + (a.mazaneh_705_sell ? ' / ' + zgNumber(a.mazaneh_705_sell) : '') + ' تومان');
            }
            if (a.emami_coin_buy || a.emami_coin) {
              $('[data-auto-emami-coin]').text(zgNumber(a.emami_coin_buy || a.emami_coin) + (a.emami_coin_sell ? ' / ' + zgNumber(a.emami_coin_sell) : '') + ' تومان');
            }
            if (a.half_coin_buy || a.half_coin) {
              $('[data-auto-half-coin]').text(zgNumber(a.half_coin_buy || a.half_coin) + (a.half_coin_sell ? ' / ' + zgNumber(a.half_coin_sell) : '') + ' تومان');
            }
            if (a.quarter_coin_buy || a.quarter_coin) {
              $('[data-auto-quarter-coin]').text(zgNumber(a.quarter_coin_buy || a.quarter_coin) + (a.quarter_coin_sell ? ' / ' + zgNumber(a.quarter_coin_sell) : '') + ' تومان');
            }
            if (a.emami_intrinsic) {
              $('[data-auto-emami-intrinsic]').text(zgNumber(a.emami_intrinsic) + ' تومان');
            }
            if (a.half_coin_intrinsic && a.quarter_coin_intrinsic) {
              $('[data-auto-sub-intrinsic]').text(zgNumber(a.half_coin_intrinsic) + ' / ' + zgNumber(a.quarter_coin_intrinsic) + ' تومان');
            }
          }
          if (r.data.snapshot && r.data.snapshot.prices) {
            $.each(r.data.snapshot.prices, function(k, v){
              var $el = $('[data-live-item="'+k+'"] b');
              if ($el.length && Number(v) > 0) {
                $el.text(zgNumber(v) + ' ' + currency);
                zgFlash($el, Number(v));
              }
            });
          }
          if (r.data.snapshot && r.data.snapshot.sell_prices) {
            $.each(r.data.snapshot.sell_prices, function(k, v){
              var $el = $('[data-live-sell-item="'+k+'"] b');
              if ($el.length && Number(v) > 0) {
                $el.text(zgNumber(v) + ' ' + currency);
              }
            });
          }
          if ($indicator.length) { $indicator.text('● زنده'); }
          if ($autoIndicator.length) { $autoIndicator.text('● زنده'); }
        });
      };
      loadLiveStatus();
      window.setInterval(loadLiveStatus, 10000);

      $(document).on('click', '[data-direct-test]', function(){
        var $btn = $(this), $res = $('[data-direct-test-result]');
        var payload = {
          action: 'zg_test_direct_api',
          nonce: ZGAdmin.nonce,
          url: $sourcesForm.find('[name="direct_api_url"]').val(),
          method: $sourcesForm.find('[name="direct_api_method"]').val(),
          format: $sourcesForm.find('[name="direct_api_format"]').val(),
          params: $sourcesForm.find('[name="direct_api_params"]').val(),
          buy_path: $sourcesForm.find('[name="direct_api_buy_path"]').val(),
          sell_path: $sourcesForm.find('[name="direct_api_sell_path"]').val(),
          buy_path_24k: $sourcesForm.find('[name="direct_api_buy_path_24k"]').val(),
          sell_path_24k: $sourcesForm.find('[name="direct_api_sell_path_24k"]').val(),
          unit: $sourcesForm.find('[name="direct_api_unit"]').val(),
          token: $sourcesForm.find('[name="direct_api_token"]').val()
        };
        $btn.prop('disabled', true); $res.removeClass('is-error').text('در حال تست…');
        $.post(ZGAdmin.ajaxUrl, payload).done(function(r){
          if(r && r.success){
            var vals = [];
            $.each(r.data.values||{}, function(k,v){ vals.push(zgKeyLabel(k)+': '+zgNumber(v)); });
            $res.removeClass('is-error').text('تست موفق — ' + vals.join(' • '));
          } else {
            $res.addClass('is-error').text('ناموفق: ' + (r && r.data && r.data.message ? r.data.message : 'خطای نامشخص'));
          }
        }).fail(function(xhr){
          var msg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : 'خطا در ارتباط با سرور';
          $res.addClass('is-error').text('ناموفق: ' + msg);
        }).always(function(){ $btn.prop('disabled', false); });
      });
    }

    /* ---------- گزارش‌ها: به‌روزرسانی زنده بدون ری‌لود ---------- */
    var $reportsHistory = $('[data-reports-history]');
    if ($reportsHistory.length) {
      var reportsLoading = false;
      var loadReports = function(){
        if (reportsLoading) { return; } reportsLoading = true;
        var $indicator = $('[data-reports-indicator]');
        $.post(ZGAdmin.ajaxUrl, { action: 'zg_reports_live', nonce: ZGAdmin.nonce }).done(function(r){
          if (!r || !r.success) { if($indicator.length){$indicator.text('خطا در دریافت گزارش زنده');} return; }
          if (r.data.history) { zgSwap($('[data-reports-history]'), r.data.history); }
          if (r.data.products) { zgSwap($('[data-reports-products]'), r.data.products); }
          if (r.data.events) { zgSwap($('[data-reports-events]'), r.data.events); }
          if($indicator.length){$indicator.text('زنده • هر ۵ ثانیه به‌روز می‌شود');}
        }).fail(function(){ if($indicator.length){$indicator.text('اتصال برقرار نشد؛ دوباره تلاش می‌شود');} }).always(function(){ reportsLoading = false; });
      };
      loadReports();
      window.setInterval(loadReports, 5000);
    }
  });
})(jQuery);
