export async function handleOAuthRedirect(request, env) {
  const url = new URL(request.url);
  const code = url.searchParams.get('code');
  const stateStr = url.searchParams.get('state');
  const error = url.searchParams.get('error');

  const renderError = (message) => {
    const html = `
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>OAuth Error</title>
      <style>
        body {
          font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
          background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
          margin: 0;
          padding: 0;
          display: flex;
          align-items: center;
          justify-content: center;
          min-height: 100vh;
        }
        .card {
          background: white;
          padding: 40px;
          border-radius: 8px;
          box-shadow: 0 4px 6px rgba(0,0,0,0.1);
          max-width: 500px;
          width: 90%;
          text-align: center;
        }
        h1 { color: #d32f2f; margin-top: 0; }
        p { color: #555; line-height: 1.5; }
      </style>
    </head>
    <body>
      <div class="card">
        <h1>Authorization Error</h1>
        <p>${message}</p>
      </div>
    </body>
    </html>
    `;
    return new Response(html, {
      headers: { 'Content-Type': 'text/html;charset=UTF-8' },
      status: 400
    });
  };

  // 1. Parse query params (already done above)

  if (!stateStr) {
    return renderError('Missing state parameter');
  }

  // 2. Decode state
  let state;
  try {
    state = JSON.parse(atob(stateStr));
  } catch (e) {
    return renderError('Invalid state parameter');
  }

  const returnUrl = state.return_url;
  if (!returnUrl) {
    return renderError('Missing return_url in state');
  }

  // 3. Validate return_url is a valid URL
  let parsedReturnUrl;
  try {
    parsedReturnUrl = new URL(returnUrl);
  } catch (e) {
    return renderError('Invalid return_url');
  }

  const origin = parsedReturnUrl.origin;

  // 4. Check allowed origins
  let isAllowed = false;

  // List all active license domains from KV.
  const licensePrefix = 'license:';
  const licenses = await env.GADS_KV.list({ prefix: licensePrefix });

  for (const key of licenses.keys) {
    const licenseStr = await env.GADS_KV.get(key.name);
    if (!licenseStr) continue;

    try {
      const license = JSON.parse(licenseStr);
      if (license.active && license.domain && originMatchesDomain(origin, license.domain)) {
        isAllowed = true;
        break;
      }
    } catch (e) {
      // Ignore malformed license rows.
    }
  }

  // Also check KV config:allowed_origins
  if (!isAllowed) {
    const allowedOriginsStr = await env.GADS_KV.get('config:allowed_origins');
    if (allowedOriginsStr) {
      try {
        const allowedOrigins = JSON.parse(allowedOriginsStr);
        if (Array.isArray(allowedOrigins) && allowedOrigins.includes(origin)) {
          isAllowed = true;
        }
      } catch (e) {
        // Ignore JSON parse error
      }
    }
  }

  if (!isAllowed) {
    return renderError('Origin not allowed for OAuth redirect');
  }

  // 5. If error: redirect to return_url with oauth error params
  if (error) {
    parsedReturnUrl.searchParams.set('oauth_error', error);
    const errorDescription = url.searchParams.get('error_description') || 'Unknown error';
    parsedReturnUrl.searchParams.set('oauth_error_description', errorDescription);
    return Response.redirect(parsedReturnUrl.toString(), 302);
  }

  // 6. If code: redirect to return_url with code & success param
  if (code) {
    parsedReturnUrl.searchParams.set('code', code);
    parsedReturnUrl.searchParams.set('oauth_success', '1');
    return Response.redirect(parsedReturnUrl.toString(), 302);
  }

  // 7. No code and no error
  return renderError('Invalid OAuth response: missing code and error');
}

function originMatchesDomain(origin, domain) {
  const normalizedDomain = String(domain).trim();
  if (!normalizedDomain) return false;

  try {
    const originUrl = new URL(origin);
    const domainUrl = new URL(
      normalizedDomain.includes('://') ? normalizedDomain : `https://${normalizedDomain}`
    );

    return originUrl.hostname === domainUrl.hostname;
  } catch (e) {
    return origin.includes(normalizedDomain);
  }
}
