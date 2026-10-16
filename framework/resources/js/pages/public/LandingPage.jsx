import { backendUrl } from "../../config/api.js";
import { useEffect, useRef, useState } from "react";
import { Link } from "react-router-dom";
import "../../../css/public/landing-page.css";
import usePublicTheme from "../../hooks/usePublicTheme.js";
import PublicThemeSwitch from "../../components/shared/PublicThemeSwitch.jsx";
import {
  cartItemCount,
  readGuestCart,
  reconcileCartWithProducts,
  writeGuestCart,
} from "../../services/customer/cartStorage.js";

const Logo = backendUrl("/storage/site/Logo.png");
const mainpic = backendUrl("/storage/site/mainpic.jpg");

const services = [
  {
    title: "Portable AC Units",
    image: backendUrl("/storage/products/PortableAcUnits.jpg"),
    className: "thumb-one",
  },
  {
    title: "Air Purifiers",
    image: backendUrl("/storage/products/AirPurifier.jpg"),
    className: "thumb-two",
  },
  {
    title: "Replacement Filters",
    image: backendUrl("/storage/products/ReplacementFilter.webp"),
    className: "thumb-three",
  },
  {
    title: "Smart Thermostats",
    image: backendUrl("/storage/products/SmartThermostat.jpg"),
    className: "thumb-four",
  },
];

const fallbackProducts = [
  {
    product_id: "fallback-1",
    name: "Portable AC Pro",
    description: "Cool rooms fast and quietly",
    unit_price: 18500,
    image_url: backendUrl("/storage/products/PortableAcUnits.jpg"),
    available_stock: 30,
  },
  {
    product_id: "fallback-2",
    name: "Air Purifier Plus",
    description: "Cleaner indoor air",
    unit_price: 12900,
    image_url: backendUrl("/storage/products/AirPurifier.jpg"),
    available_stock: 50,
  },
  {
    product_id: "fallback-3",
    name: "Carbon Filter Pack",
    description: "High-efficiency replacement",
    unit_price: 2400,
    image_url: backendUrl("/storage/products/ReplacementFilter.webp"),
    available_stock: 100,
  },
  {
    product_id: "fallback-4",
    name: "Smart Thermostat",
    description: "Energy-saving control",
    unit_price: 8750,
    image_url: backendUrl("/storage/products/SmartThermostat.jpg"),
    available_stock: 20,
  },
];

function normalizeImagePath(value) {
  if (!value) return null;
  if (/^https?:\/\//i.test(value)) return value;

  if (value.startsWith("storage/")) {
    return backendUrl(`/${value}`);
  }

  if (value.startsWith("/")) {
    return backendUrl(value);
  }

  return backendUrl(`/storage/${value}`);
}

function normalizeProduct(product, index) {
  return {
    product_id:
      product.product_id ??
      product.id ??
      product.sku ??
      `product-${index}`,
    name: product.name ?? product.product_name ?? "Unnamed Product",
    description: product.description ?? "",
    unit_price: Number(
      product.unit_price ??
      product.price ??
      product.selling_price ??
      0
    ),
    available_stock: Number(
      product.available_stock ??
      product.current_stock ??
      product.stock ??
      product.current_quantity ??
      0
    ),
    image_url: normalizeImagePath(
      product.image_url ??
      product.image_path ??
      product.primary_image ??
      product.image
    ),
  };
}

function formatPeso(value) {
  return new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
    minimumFractionDigits: 2,
  }).format(Number(value || 0));
}

function LandingPage() {
  const pageRef = useRef(null);
  const { theme, toggleTheme } = usePublicTheme();
  const [cart, setCart] = useState(() => readGuestCart());
  const [products, setProducts] = useState(fallbackProducts);
  const [productsAreLive, setProductsAreLive] = useState(false);
  const [activeSession, setActiveSession] = useState(null);
  const [websiteContent, setWebsiteContent] = useState(null);

  useEffect(() => {
    let active = true;

    fetch(backendUrl("/api/public/website-content"), {
      credentials: "include",
      headers: { Accept: "application/json" },
    })
      .then(async (response) => {
        if (!response.ok) return null;
        return response.json();
      })
      .then((payload) => {
        if (active && payload) {
          setWebsiteContent(payload);
        }
      })
      .catch(() => {
        // Keep the existing landing-page fallback content.
      });

    return () => {
      active = false;
    };
  }, []);
  const [collapsed, setCollapsed] = useState({
    about: false,
    services: false,
    products: false,
  });

  useEffect(() => {
    let cancelled = false;

    fetch(backendUrl("/api/session/status"), {
      credentials: "include",
      headers: { Accept: "application/json" },
    })
      .then(async (response) => {
        if (!response.ok) return null;
        return response.json();
      })
      .then((data) => {
        if (!cancelled && data?.authenticated) {
          setActiveSession(data);
        }
      })
      .catch(() => {
        // Public landing page remains usable when no session is active.
      });

    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    let active = true;

    async function loadProducts() {
      try {
        const response = await fetch(backendUrl("/api/store/products"), {
          method: "GET",
          credentials: "include",
          headers: {
            Accept: "application/json",
          },
        });

        if (!response.ok) return;

        const payload = await response.json();
        const list = Array.isArray(payload)
          ? payload
          : payload.products ?? payload.data ?? [];

        if (active && Array.isArray(list) && list.length > 0) {
          const normalizedProducts = list.map(normalizeProduct);

          setProducts(normalizedProducts);
          setProductsAreLive(true);
          setCart((current) => {
            const reconciled = reconcileCartWithProducts(
              current,
              normalizedProducts
            );
            writeGuestCart(reconciled);
            return reconciled;
          });
        }
      } catch {
        // Keep the design visible with fallback cards if the API is temporarily unavailable.
      }
    }

    loadProducts();

    return () => {
      active = false;
    };
  }, []);

  useEffect(() => {
    const revealItems = pageRef.current?.querySelectorAll(".reveal-up") ?? [];

    const revealObserver = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            const item = entry.target;
            item.classList.remove("is-visible");
            void item.offsetWidth;
            item.classList.add("is-visible");
          } else {
            entry.target.classList.remove("is-visible");
          }
        });
      },
      {
        threshold: 0.18,
        rootMargin: "0px 0px -8% 0px",
      }
    );

    revealItems.forEach((item) => revealObserver.observe(item));

    return () => revealObserver.disconnect();
  }, [products]);

  useEffect(() => {
    const handleResize = () => {
      const mobile = window.innerWidth <= 640;
      setCollapsed({
        about: mobile,
        services: mobile,
        products: mobile,
      });
    };

    handleResize();
    window.addEventListener("resize", handleResize);

    return () => window.removeEventListener("resize", handleResize);
  }, []);

  const toggleSection = (section) => {
    setCollapsed((previous) => ({
      ...previous,
      [section]: !previous[section],
    }));
  };

  const cartCount = cartItemCount(cart);

  const activeDashboardPath =
    activeSession?.role === "super_admin"
      ? "/super-admin"
      : activeSession?.role === "System_User"
        ? "/user"
        : null;

  const activeDashboardLabel =
    activeSession?.role === "super_admin"
      ? "Back to Super Admin"
      : "Back to Account";

  const addToCart = (product) => {
    const productId = Number(product.product_id);
    const stock = Math.max(0, Math.floor(Number(product.available_stock) || 0));

    if (
      !productsAreLive ||
      !Number.isInteger(productId) ||
      productId <= 0 ||
      stock <= 0
    ) {
      return;
    }

    setCart((current) => {
      const quantity = current[productId]?.quantity ?? 0;

      if (quantity >= stock) {
        return current;
      }

      const next = {
        ...current,
        [productId]: {
          product_id: productId,
          quantity: quantity + 1,
        },
      };

      writeGuestCart(next);
      return next;
    });
  };

  const handleNewsletter = (event) => {
    event.preventDefault();
    alert("Thank you for joining our newsletter!");
    event.currentTarget.reset();
  };

  return (
    <div
      ref={pageRef}
      className="page-shell wbo-landing"
      data-theme={theme}
    >
      <header className="site-header">
        <div className="max-width-container header-top">
          <div className="header-brand">
            <img
              src={Logo}
              alt="Walang Brown Out Logo"
              width="45"
              height="45"
            />

            <div className="brand-text">
              <span className="republic-text">
                Republic of the Philippines
              </span>
              <div className="brand-title">WALANG BROWN OUT</div>
            </div>
          </div>

          <div className="nav-actions">
            <PublicThemeSwitch
              theme={theme}
              onToggle={toggleTheme}
            />

            {activeDashboardPath ? (
              <Link to={activeDashboardPath} className="nav-button primary-nav-button">
                {activeDashboardLabel}
              </Link>
            ) : (
              <>
                <Link to="/login" className="nav-button">
                  Login
                </Link>

                <Link to="/signup" className="nav-button primary-nav-button">
                  Create Account
                </Link>
              </>
            )}

            <button className="cart-button" type="button" aria-label={`${cartCount} item${cartCount === 1 ? "" : "s"} in cart`}>
              Cart (<span aria-live="polite">{cartCount}</span>)
            </button>
          </div>
        </div>

        <nav className="main-nav" aria-label="Main navigation">
          <a href="#home">Home</a>
          <a href="#solutions">Solutions</a>
          <a href="#features">Features</a>
          <a href="#inventory">Inventory</a>
          <a href="#about">About</a>
          <Link to="/faq">FAQ</Link>
        </nav>
      </header>

      <main className="storefront">
        <section id="home" className="promo-banner reveal-up">
          <div className="promo-copy">
            <span className="promo-tag">Home Comfort Solutions</span>

            <h1>Comfort at home, all year round.</h1>

            <p>
              Walang BrownOut Appliances helps homes and businesses stay cool,
              clean, and efficient with trusted portable AC units, air
              purifiers, smart thermostats, and replacement filters.
            </p>

            <div className="promo-actions">
              <a href="#solutions" className="primary-link">
                Explore Solutions
              </a>

              <Link
                to={activeDashboardPath || "/login"}
                className="secondary-link"
              >
                {activeDashboardPath ? activeDashboardLabel : "View Dashboard"}
              </Link>
            </div>
          </div>

          <div className="promo-visual" aria-label="Appliance display image">
            <img src={mainpic} alt="Walang BrownOut Appliances" loading="eager" fetchPriority="high" />
          </div>
        </section>

        <section
          id="about"
          style={websiteContent?.about?.visible === false ? { display: "none" } : undefined}
          className={`categories-section ${
            collapsed.about ? "collapsed" : ""
          }`}
        >
          <div className="section-heading">
            <h2>{websiteContent?.about?.title || "About Walang BrownOut"}</h2>
            <a href="#about">Learn more</a>

            <button
              className="section-toggle"
              aria-expanded={!collapsed.about}
              aria-label="Toggle about section"
              onClick={() => toggleSection("about")}
              type="button"
            >
              <span className="chev" aria-hidden="true">{"\u25BE"}</span>
            </button>
          </div>

          <div className="about-box reveal-up">
            <p>
              {websiteContent?.about?.description ||
                "Walang BrownOut Appliances is a regional distributor of home comfort products dedicated to improving everyday living through dependable cooling, clean air, and smarter energy use. We serve households, offices, and retail partners with efficient solutions built for comfort, health, and performance."}
            </p>
          </div>
        </section>

        <section
          id="solutions"
          className={`categories-section ${
            collapsed.services ? "collapsed" : ""
          }`}
        >
          <div className="section-heading">
            <h2>Our services</h2>
            <a href="#solutions">Explore</a>

            <button
              className="section-toggle"
              aria-expanded={!collapsed.services}
              aria-label="Toggle services section"
              onClick={() => toggleSection("services")}
              type="button"
            >
              <span className="chev" aria-hidden="true">{"\u25BE"}</span>
            </button>
          </div>

          <div className="category-grid">
            {services.map((service, index) => (
              <article
                className={`category-card reveal-up stagger-${index + 1}`}
                key={service.title}
              >
                <div className={`category-thumb ${service.className}`}>
                  <img src={service.image} alt={service.title} loading="lazy" decoding="async" />
                </div>
                <h3>{service.title}</h3>
              </article>
            ))}
          </div>
        </section>

        <section
          id="inventory"
          className={`products-section ${
            collapsed.products ? "collapsed" : ""
          }`}
        >
          <div className="section-heading">
            <h2>Featured home comfort essentials</h2>
            <a href="#inventory">View all</a>

            <button
              className="section-toggle"
              aria-expanded={!collapsed.products}
              aria-label="Toggle products section"
              onClick={() => toggleSection("products")}
              type="button"
            >
              <span className="chev" aria-hidden="true">{"\u25BE"}</span>
            </button>
          </div>

          <div className="product-grid">
            {products.map((product, index) => (
              <article
                className={`product-card reveal-up stagger-${
                  (index % 4) + 1
                }`}
                key={product.product_id}
              >
                <div className="product-image">
                  {product.image_url ? (
                    <img
                      src={backendUrl(product.image_url)}
                      alt={product.name}
                      loading="lazy"
                      decoding="async"
                    />
                  ) : null}
                  <span className={`stock-badge ${product.available_stock > 0 ? "in-stock" : "out-of-stock"}`}>
                    {product.available_stock > 0
                      ? `${product.available_stock} in stock`
                      : "Out of stock"}
                  </span>
                </div>

                <div className="product-info">
                  <h3>{product.name}</h3>
                  <p>{product.description}</p>

                  <div className="product-meta">
                    <span className="price">
                      {formatPeso(product.unit_price)}
                    </span>

                    <button
                      type="button"
                      onClick={() => addToCart(product)}
                      disabled={
                        product.available_stock <= 0 || !productsAreLive
                      }
                    >
                      {!productsAreLive
                        ? "Catalog unavailable"
                        : product.available_stock > 0
                          ? "Add to cart"
                          : "Out of stock"}
                    </button>
                  </div>
                </div>
              </article>
            ))}
          </div>
        </section>

        <section id="features" className="promo-strip reveal-up">
          <div>
            <span className="promo-badge">Real-time stock</span>
            <h3>Inventory from live batches</h3>
          </div>

          <div>
            <span className="promo-badge">Secure accounts</span>
            <h3>Email OTP verification</h3>
          </div>

          <div>
            <span className="promo-badge">Role based</span>
            <h3>Correct dashboard access</h3>
          </div>
        </section>

        <section className="newsletter-box reveal-up">
          <div>
            <span className="newsletter-tag">Newsletter</span>
            <h2>Stay updated with WalangBrownOut.</h2>
          </div>

          <form className="newsletter-form" onSubmit={handleNewsletter}>
            <input type="email" placeholder="Enter your email" required />
            <button type="submit">Join now</button>
          </form>
        </section>
      </main>

      <footer className="site-footer reveal-up">
        <div className="footer-grid">
          <div className="footer-brand">
            <div className="brand">Walang BrownOut</div>

            <p>
              A secure, role-based warehouse and inventory management platform
              for the WalangBrownout Appliances workflow.
            </p>
          </div>

          <div className="footer-column">
            <h4>Shop</h4>
            <a href="#inventory">New arrivals</a>
            <a href="#inventory">Best sellers</a>
            <a href="#inventory">Accessories</a>
            <a href="#inventory">Sale</a>
          </div>

          <div className="footer-column">
            <h4>Company</h4>
            <a href="#about">About us</a>
            <a href="#features">Features</a>
            <Link to="/login">Login</Link>
            <Link to="/signup">Create Account</Link>
          </div>

          <div className="footer-column">
            <h4>Support</h4>
            <a href="#solutions">Solutions</a>
            <a href="#inventory">Inventory</a>
            <Link to="/faq">FAQs</Link>
            <a href="#about">Privacy</a>
          </div>
        </div>

        <div className="footer-bottom">
          <span>
            <strong>&copy; 2026 WalangBrownOut.</strong> All rights reserved.
          </span>

          <div className="social-links">
            <a href="#">Instagram</a>
            <a href="#">Facebook</a>
            <a href="#">X</a>
          </div>
        </div>
      </footer>
    </div>
  );
}

export default LandingPage;
