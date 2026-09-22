(function (Drupal, once) {
  Drupal.behaviors.upstreamEmbedShadow = {
    attach: function attach(context) {
      once("upstream-embed-shadow", ".upstream-embed-host", context).forEach(function (host) {
        if (host.shadowRoot) {
          return;
        }
        const template = host.querySelector("template.upstream-embed-src");
        if (!template) {
          return;
        }
        const shadow = host.attachShadow({ mode: "open" });
        shadow.appendChild(template.content.cloneNode(true));
        shadow.querySelectorAll("script").forEach(function (old) {
          try {
            Function(old.textContent)();
          } catch (err) {
            console.error("upstream_embed inline script", err);
          }
          old.remove();
        });
        ["upstreamEmbedInit"].forEach(function (name) {
          if (typeof window[name] === "function") {
            window[name](shadow);
          }
        });
      });
    },
  };
})(Drupal, once);
