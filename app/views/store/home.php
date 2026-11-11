<?php
$decodedSliderSlides=json_decode((string)($settings['slider_slides_json']??'[]'),true);if(!is_array($decodedSliderSlides))$decodedSliderSlides=[];
$customSliderSlides=array_values(array_filter($decodedSliderSlides,static fn($s)=>is_array($s)&&!empty($s['active'])&&!empty($s['title'])));
$sliderProducts=array_slice($products??[],0,5);
$useCustomSlider=count($customSliderSlides)>0;
$sliderItems=$useCustomSlider?$customSliderSlides:$sliderProducts;
$sliderCount=count($sliderItems);
?>
<?php if($sliderCount>0): ?>
<section class="game-showcase" aria-label="<?= e($settings['slider_aria_label']??'Öne çıkan oyunlar') ?>" data-game-slider data-autoplay="<?= e($settings['slider_autoplay']??'1') ?>" data-interval="<?= e((int)($settings['slider_interval']??6500)) ?>">
 <div class="game-showcase-track">
 <?php foreach($sliderItems as $i=>$slide): $slideTitle=$useCustomSlider?(string)$slide['title']:(string)$slide['name'];$slideDescription=$useCustomSlider?(string)($slide['description']??''):(string)($slide['short_description']??'');$slideImage=(string)($slide['image']??'');$slideLink=$useCustomSlider?(string)($slide['link']??'#'):'product/'.rawurlencode((string)$slide['slug']);$slideButton=$useCustomSlider?(string)($slide['button']??'HEMEN KEŞFET'):($settings['slider_button_text']??'HEMEN KEŞFET'); ?>
  <article class="game-slide<?= $i===0?' is-active':'' ?>" data-slide="<?= $i ?>" aria-hidden="<?= $i===0?'false':'true' ?>">
   <?php if($slideImage!==''): ?><img class="game-slide-image" src="<?= e(preg_match('~^https?://~i',$slideImage)?$slideImage:url($slideImage)) ?>" alt="" <?= $i===0?'fetchpriority="high"':'loading="lazy"' ?>><?php endif; ?>
   <div class="game-slide-shade"></div><div class="game-slide-content">
    <span class="game-slide-kicker"><i></i><?= e($settings['slider_kicker']??'VİTRİNDE ÖNE ÇIKANLAR') ?></span>
    <span class="game-slide-index"><?= sprintf('%02d',$i+1) ?> <span>/ <?= sprintf('%02d',$sliderCount) ?></span></span>
    <h1><?= e($slideTitle) ?></h1>
    <?php if($slideDescription!==''): ?><p><?= e($slideDescription) ?></p><?php endif; ?>
    <div class="game-slide-actions"><a class="game-slide-cta" href="<?= e(preg_match('~^https?://~i',$slideLink)?$slideLink:($slideLink==='#'?'#':url(ltrim($slideLink,'/')))) ?>" <?= preg_match('~^https?://~i',$slideLink)?'target="_blank" rel="noopener"':'' ?>><?= e($slideButton) ?><span aria-hidden="true">↗</span></a>
     <?php if(!$useCustomSlider&&isset($slide['price'])): ?><strong><?= number_format((float)$slide['price'],2,',','.') ?> <small>₺</small></strong><?php endif; ?>
    </div></div><div class="game-slide-watermark" aria-hidden="true">PLAY<span>.</span></div>
  </article>
 <?php endforeach; ?>
 </div>
 <?php if($sliderCount>1): ?><div class="game-slider-controls"><div class="game-slider-dots" role="group" aria-label="<?= e($settings['slider_controls_label']??'Slider sayfaları') ?>">
 <?php foreach($sliderItems as $i=>$slide): ?><button type="button" class="game-slider-dot<?= $i===0?' is-active':'' ?>" data-go-to="<?= $i ?>" aria-label="<?= e(($settings['slider_slide_label']??'Slayt').' '.($i+1)) ?>" aria-current="<?= $i===0?'true':'false' ?>"></button><?php endforeach; ?>
 </div><div class="game-slider-arrows"><button type="button" data-slider-prev aria-label="<?= e($settings['slider_previous_label']??'Önceki') ?>">←</button><button type="button" data-slider-next aria-label="<?= e($settings['slider_next_label']??'Sonraki') ?>">→</button></div></div><div class="game-slider-progress" aria-hidden="true"><span></span></div><?php endif; ?>
</section>
<?php else: ?><section class="game-showcase game-showcase-empty"><div class="game-slide-shade"></div><div class="game-slide-content"><span class="game-slide-kicker"><i></i><?= e($settings['slider_kicker']??'OYUN DÜNYASINA HOŞ GELDİN') ?></span><h1><?= e($settings['home_heading']??($settings['site_title']??'')) ?></h1><p><?= e($settings['home_description']??'') ?></p></div><div class="game-slide-watermark" aria-hidden="true">PLAY<span>.</span></div></section><?php endif; ?>
<?php if($categories): ?><section class="home-categories"><div class="home-section-heading"><div><span class="home-section-kicker"><?= e($settings['category_kicker']??'KEŞFET') ?></span><h2><?= e($settings['category_heading']??'Oyun kategorileri') ?></h2></div></div><div class="chips"><?php foreach($categories as $c): ?><a class="chip" href="<?= e(url('category/'.rawurlencode($c['slug']))) ?>"><?= e($c['name']) ?><span aria-hidden="true">↗</span></a><?php endforeach; ?></div></section><?php endif; ?>
<section class="home-featured"><div class="home-section-heading"><div><span class="home-section-kicker"><?= e($settings['featured_kicker']??'SENİN İÇİN SEÇTİK') ?></span><h2><?= e($settings['featured_heading']??'Öne çıkan ürünler') ?></h2></div></div><?php if(!$products): ?><div class="empty">Henüz yayında ürün bulunmuyor.</div><?php else: ?><div class="products"><?php foreach($products as $p): ?><article class="product"><a class="product-link" href="<?= e(url('product/'.rawurlencode($p['slug']))) ?>"><div class="product-image"><?php if($p['image']): ?><img src="<?= e($p['image']) ?>" alt="<?= e($p['name']) ?>" loading="lazy"><?php else: ?><span>GAME</span><?php endif; ?></div><h3><?= e($p['name']) ?></h3><p><?= e($p['short_description']) ?></p><strong><?= number_format((float)$p['price'],2,',','.') ?> ₺</strong></a></article><?php endforeach; ?></div><?php endif; ?></section>
