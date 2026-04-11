<?php
/**
 * Chatbot Loader
 * Embeds the AnyChat.one widget.
 */
?>
<!-- AnyChat Widget -->
<script>(function(d, s, id){
  var js, fjs = d.getElementsByTagName(s)[0];
  if (d.getElementById(id)) return;
  js = d.createElement(s); js.id = id;
  js.src = 'https://api.anychat.one/widget/bc20b452-0176-3547-901d-8bf82f45a402?r=' + encodeURIComponent(window.location);
  fjs.parentNode.insertBefore(js, fjs);
}(document, 'script', 'contactus-jssdk'));</script>