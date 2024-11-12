<?php
// Register the admin page
add_action('acf/init', function () {
    if (function_exists('acf_add_options_page')) {
        acf_add_options_sub_page(array(
            'page_title'    => 'Deploy to Vercel',
            'menu_title'    => 'Deploy',
            'parent_slug'   => 'nextpress',
            'menu_slug'     => 'deploy',
        ));
    }
});

// Add the admin page content
add_action('admin_init', function () {
    add_action('nextpress_page_deploy', function () {
        // Your GitHub repo URL - replace with your actual repo URL
        $repo_url = 'https://github.com/pomeg-dev/nextpress-boiler';

        // Get the current site URL
        $current_site_url = get_site_url();

        // Generate the Vercel deploy URL
        $deploy_url = 'https://vercel.com/new/clone?repository-url=' . urlencode($repo_url);

        // Define WordPress-specific environment variables
        $env_vars = array(
            'NEXT_PUBLIC_API_URL' => $current_site_url,
            'NEXT_PUBLIC_CMS_MODE' => 'wordpress'
        );

        if (!empty($env_vars)) {
            $deploy_url .= '&env=' . implode(',', array_keys($env_vars));
        }
?>
        <div class="wrap">
            <h1>Deploy to Vercel</h1>

            <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
                <h2>Quick Deploy</h2>
                <p>Click the button below to deploy this project to your Vercel account:</p>

                <div style="margin: 20px 0;">
                    <a href="<?php echo esc_url($deploy_url); ?>" target="_blank">
                        <img src="https://vercel.com/button" alt="Deploy with Vercel" />
                    </a>
                </div>

                <div class="env-vars" style="margin-top: 20px;">
                    <h3>Environment Variables</h3>
                    <p>The following environment variables will be automatically configured:</p>
                    <ul>
                        <?php foreach ($env_vars as $key => $value): ?>
                            <li><strong><?php echo esc_html($key); ?></strong> - <?php echo esc_html($value); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
<?php
    });
});
