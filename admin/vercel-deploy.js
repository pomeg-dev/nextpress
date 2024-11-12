console.log("Vercel Deploy script loaded");
(function ($) {
  "use strict";

  const VERCEL_AUTH_URL = "https://vercel.com/oauth/authorize";
  let accessToken = null;

  function init() {
    // Check for OAuth callback
    const urlParams = new URLSearchParams(window.location.search);
    const code = urlParams.get("code");

    if (code) {
      handleOAuthCallback(code);
    }

    bindEvents();
  }

  function bindEvents() {
    $("#vercel-login").on("click", initiateOAuth);
    $("#add-env-var").on("click", addEnvVarRow);
    $("#deploy-button").on("click", handleDeploy);
    $("#env-vars-container").on("click", ".remove-env-var", function () {
      $(this).closest(".env-var-row").remove();
    });
  }
  function initiateOAuth() {
    console.log("Initiating OAuth flow...");
    const params = new URLSearchParams({
      client_id: vercelConfig.client_id,
      redirect_uri: vercelConfig.redirect_uri,
      scope: vercelConfig.scope,
      response_type: "code",
    });

    window.location.href = `${VERCEL_AUTH_URL}?${params.toString()}`;
  }

  async function handleOAuthCallback(code) {
    try {
      // Exchange code for access token using your backend endpoint
      const response = await fetch("/wp-json/vercel-deploy/v1/token", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({ code }),
      });

      const data = await response.json();
      if (data.access_token) {
        accessToken = data.access_token;
        showDeployForm();
        updateStatus("Connected to Vercel successfully!", "success");
      }
    } catch (error) {
      updateStatus("Failed to connect to Vercel. Please try again.", "error");
    }
  }

  function addEnvVarRow() {
    const row = `
            <div class="env-var-row">
                <input type="text" placeholder="KEY" class="env-key regular-text">
                <input type="text" placeholder="VALUE" class="env-value regular-text">
                <button class="button remove-env-var">Remove</button>
            </div>
        `;
    $("#env-vars-container").append(row);
  }

  async function handleDeploy() {
    const projectName = $("#project-name").val();
    if (!projectName) {
      updateStatus("Please enter a project name", "error");
      return;
    }

    const envVars = [];
    $(".env-var-row").each(function () {
      const key = $(this).find(".env-key").val();
      const value = $(this).find(".env-value").val();
      if (key && value) {
        envVars.push({ key, value });
      }
    });

    try {
      updateStatus("Starting deployment...", "info");
      $("#deploy-button").prop("disabled", true);

      const response = await fetch("/wp-json/vercel-deploy/v1/deploy", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${accessToken}`,
        },
        body: JSON.stringify({
          projectName,
          envVars,
        }),
      });

      const data = await response.json();
      if (data.success) {
        updateStatus(
          "Deployment started successfully! Check your Vercel dashboard for status.",
          "success"
        );
      } else {
        throw new Error(data.message || "Deployment failed");
      }
    } catch (error) {
      updateStatus("Deployment failed: " + error.message, "error");
    } finally {
      $("#deploy-button").prop("disabled", false);
    }
  }

  function showDeployForm() {
    $(".vercel-auth-section").hide();
    $("#vercel-deploy-form").show();
  }

  function updateStatus(message, type = "info") {
    const colors = {
      success: "#46b450",
      error: "#dc3232",
      info: "#00a0d2",
    };

    $("#vercel-status").html(`
            <div class="notice" style="color: ${colors[type]}; padding: 10px;">
                ${message}
            </div>
        `);
  }

  $(init);
})(jQuery);
