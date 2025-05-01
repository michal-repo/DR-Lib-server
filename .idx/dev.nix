# To learn more about how to use Nix to configure your environment
# see: https://firebase.google.com/docs/studio/customize-workspace
{ pkgs, ... }: {
  # Which nixpkgs channel to use.
  channel = "stable-24.05"; # or "unstable"

  # Use https://search.nixos.org/packages to find packages
  packages = [
    pkgs.php82
    pkgs.php82Packages.composer
  ];

  services.mysql = {
    enable = true;
    package = pkgs.mariadb;
  };


  # Sets environment variables in the workspace
  env = { };
  idx = {
    # Search for the extensions you want on https://open-vsx.org/ and use "publisher.id"
    extensions = [
      "EchoAPI.echoapi-for-vscode"
      "cweijan.vscode-database-client2"
      "cweijan.dbclient-jdbc"
      "bmewburn.vscode-intelephense-client"
      "esbenp.prettier-vscode"
    ];


    # Enable previews
    # previews = {
    #   enable = true;
    #   previews = {
    #     # web = {
    #     #   # Example: run "npm run dev" with PORT set to IDX's defined port for previews,
    #     #   # and show it in IDX's web preview panel
    #     #   command = ["npm" "run" "dev"];
    #     #   manager = "web";
    #     #   env = {
    #     #     # Environment variables to set for your server
    #     #     PORT = "$PORT";
    #     #   };
    #     # };
    #   };
    # };

    # Workspace lifecycle hooks
    workspace = {
      onCreate = {
        # Open editors for the following files by default, if they exist:
        default.openFiles = [ "index.php" ];
      };
      # Runs when a workspace is (re)started
      onStart = {
        run-server = "php -S localhost:3000";
      };
    };

  };
}
